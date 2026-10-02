<?php
/**
 * Plugin Name: Awesome Support Ticket Migrator
 * Description: Package-based exporter/importer for Awesome Support tickets, replies, users, terms, metadata and related media. Works between independent WordPress installations without direct database access.
 * Version: 1.0.0
 * Author: DevThrow
 * License: GPL-2.0-or-later
 *
 * IMPORTANT:
 * - Install/activate this plugin on BOTH sites.
 * - The source and destination URLs shown in documentation are placeholders.
 * - No passwords, API keys or database credentials are exported.
 * - User passwords are never migrated. New users receive random passwords.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AS_Ticket_Migrator {

	const VERSION       = '1.0.0';
	const OPTION_CONFIG = 'astm_config';
	const AJAX_NONCE    = 'astm_nonce';
	const CAPABILITY    = 'manage_options';
	const BATCH         = 25;
	const MAX_LOG       = 500;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'wp_ajax_astm_export_start', array( $this, 'ajax_export_start' ) );
		add_action( 'wp_ajax_astm_export_batch', array( $this, 'ajax_export_batch' ) );
		add_action( 'wp_ajax_astm_export_finalize', array( $this, 'ajax_export_finalize' ) );

		add_action( 'wp_ajax_astm_import_upload', array( $this, 'ajax_import_upload' ) );
		add_action( 'wp_ajax_astm_import_chunk', array( $this, 'ajax_import_chunk' ) );
		add_action( 'wp_ajax_astm_import_batch', array( $this, 'ajax_import_batch' ) );

		add_action( 'wp_ajax_astm_save_config', array( $this, 'ajax_save_config' ) );
	}

	public static function activate() {
		$defaults = self::defaults();
		if ( false === get_option( self::OPTION_CONFIG, false ) ) {
			add_option( self::OPTION_CONFIG, $defaults, '', false );
		}
	}

	public static function defaults() {
		return array(
			'ticket_post_type'      => 'ticket',
			'reply_post_types'      => array( 'ticket_reply' ),
			'metadata_extra_keys'   => array(),
			'metadata_exclude_keys' => array(
				'_edit_lock',
				'_edit_last',
			),
			'taxonomy_extra'        => array(),
			'attachment_meta_keys'  => array(
				'_thumbnail_id',
				'_wpas_attachments',
				'attachments',
				'attachment',
				'file',
				'files',
			),
			'user_meta_keys'        => array(
				'_wpas_assignee',
				'_wpas_user',
				'_wpas_agent',
				'assignee',
				'agent',
				'user_id',
				'customer_id',
				'author_id',
			),
			'url_fields'            => array(
				'content',
				'excerpt',
				'guid',
				'meta',
			),
		);
	}

	private function config() {
		$config = get_option( self::OPTION_CONFIG, array() );
		return wp_parse_args( $config, self::defaults() );
	}

	public function admin_menu() {
		add_management_page(
			'Awesome Support Migrator',
			'Awesome Support Migrator',
			self::CAPABILITY,
			'astm-migrator',
			array( $this, 'page' )
		);
	}

	public function assets( $hook ) {
		if ( 'tools_page_astm-migrator' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_add_inline_style(
			'wp-admin',
			'.astm-wrap .astm-card{background:#fff;border:1px solid #dcdcde;padding:20px;margin:18px 0;max-width:1100px}.astm-progress{height:22px;background:#eee;border-radius:4px;overflow:hidden}.astm-progress{position:relative}.astm-progress>span{display:block;height:100%;width:0;background:#2271b1;transition:width .2s}.astm-progress-label{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-weight:600;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.45)}.astm-log{background:#111;color:#ddd;padding:12px;max-height:260px;overflow:auto;font:12px/1.5 monospace;white-space:pre-wrap}.astm-good{color:#087a33}.astm-warn{color:#996800}.astm-bad{color:#b32d2e}.astm-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.astm-muted{color:#646970}@media(max-width:800px){.astm-grid{grid-template-columns:1fr}}'
		);
	}

	private function nonce_ok() {
		return current_user_can( self::CAPABILITY ) && check_ajax_referer( self::AJAX_NONCE, 'nonce', false );
	}

	public function page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to use this tool.', 'astm' ) );
		}

		$config = $this->config();
		?>
		<div class="wrap astm-wrap">
			<h1>Awesome Support Ticket Migrator <small>v<?php echo esc_html( self::VERSION ); ?></small></h1>

			<div class="notice notice-warning">
				<p><strong>Important:</strong> the source and destination URLs are intentionally not hard-coded.
				Export is performed on the source site and the ZIP is manually downloaded and uploaded to the destination site.</p>
			</div>

			<div class="astm-grid">
				<div class="astm-card">
					<h2>1. Export</h2>
					<p>Run this on the <strong>source</strong> WordPress installation.</p>
					<p>Tickets are exported from post type <code><?php echo esc_html( $config['ticket_post_type'] ); ?></code>.
					Replies default to <code>ticket_reply</code>.</p>
					<p><button type="button" class="button button-primary" id="astm-export">Start Export</button></p>
					<div id="astm-export-status"></div>
					<div class="astm-progress"><span id="astm-export-bar"></span><strong class="astm-progress-label" id="astm-export-percent">0%</strong></div>
					<p id="astm-export-message" class="astm-muted"></p>
				</div>

				<div class="astm-card">
					<h2>2. Import</h2>
					<p>Run this on the <strong>destination</strong> WordPress installation.</p>
					<p>
						<label>Mode:
							<select id="astm-mode">
								<option value="skip">Skip existing matching records</option>
								<option value="update">Update matching records</option>
								<option value="duplicate">Always create duplicates</option>
							</select>
						</label>
					</p>
					<p><label><input type="checkbox" id="astm-dry-run"> Dry run — do not write records</label></p>
					<p><input type="file" id="astm-package" accept=".zip"></p>
					<p><button type="button" class="button button-primary" id="astm-import">Validate &amp; Import</button></p>
					<div class="astm-progress"><span id="astm-import-bar"></span><strong class="astm-progress-label" id="astm-import-percent">0%</strong></div>
					<p id="astm-import-message" class="astm-muted"></p>
				</div>
			</div>

			<div class="astm-card">
				<h2>Advanced configuration</h2>
				<p class="astm-muted">These values are deliberately editable because Awesome Support extensions can add custom fields and media references.</p>
				<p><strong>Extra metadata keys</strong></p>
				<textarea id="astm-extra-meta" rows="4" style="width:100%;max-width:1000px"><?php echo esc_textarea( implode( "\n", (array) $config['metadata_extra_keys'] ) ); ?></textarea>
				<p><strong>Metadata keys to exclude</strong></p>
				<textarea id="astm-exclude-meta" rows="4" style="width:100%;max-width:1000px"><?php echo esc_textarea( implode( "\n", (array) $config['metadata_exclude_keys'] ) ); ?></textarea>
				<p><strong>Additional reply post types</strong></p>
				<textarea id="astm-replies" rows="3" style="width:100%;max-width:1000px"><?php echo esc_textarea( implode( "\n", (array) $config['reply_post_types'] ) ); ?></textarea>
				<p><button type="button" class="button" id="astm-save-config">Save configuration</button> <span id="astm-config-message"></span></p>
			</div>

			<div class="astm-card">
				<h2>Log</h2>
				<div id="astm-log" class="astm-log">Ready.</div>
			</div>

			<div class="astm-card">
				<h2>Compatibility notes</h2>
				<ul>
					<li>This tool does not copy the WordPress database and does not require server access to the other site.</li>
					<li>Passwords are never exported. Existing users are matched by email/username; unmatched users can be created with random passwords.</li>
					<li>Plugin settings, scheduled jobs, transients, API credentials and unrelated site configuration are intentionally not migrated.</li>
					<li>Awesome Support's current documentation covers version 4+ and documents custom fields, upload fields and extensions. Exact extension storage can differ, so use the configuration area for extension-specific metadata.</li>
					<li>Attachments are copied when the PHP process can read the source upload file. Files outside the WordPress uploads directory may not be packageable.</li>
				</ul>
			</div>
		</div>
		<script>
		jQuery(function($){
			const ajaxurl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			const nonce = '<?php echo esc_js( wp_create_nonce( self::AJAX_NONCE ) ); ?>';
			const log = (m) => { $('#astm-log').append('\\n' + m); $('#astm-log').scrollTop($('#astm-log')[0].scrollHeight); };

			function call(action, data, success, error) {
				data = data || {};
				data.action = action;
				data.nonce = nonce;
				$.post(ajaxurl, data).done(function(r){
					if(r && r.success){ success(r.data); }
					else { error(r && r.data ? r.data : 'Unknown error'); }
				}).fail(function(xhr){ error(xhr.responseText || 'AJAX request failed'); });
			}

			$('#astm-save-config').on('click', function(){
				call('astm_save_config', {
					extra_meta: $('#astm-extra-meta').val(),
					exclude_meta: $('#astm-exclude-meta').val(),
					replies: $('#astm-replies').val()
				}, function(d){ $('#astm-config-message').text('Saved.'); log('Configuration saved.'); },
				function(e){ $('#astm-config-message').text(e); });
			});

			$('#astm-export').on('click', function(){
				$('#astm-export').prop('disabled', true);
				$('#astm-export-bar').css('width','0%');
				log('Starting export...');
				call('astm_export_start', {}, function(d){
					log(d.message);
					astmExportBatch(d.state);
				}, function(e){ log('EXPORT ERROR: '+e); $('#astm-export').prop('disabled',false); });
			});

			function astmExportBatch(state){
				call('astm_export_batch', {state: JSON.stringify(state)}, function(d){
					const pct = d.percent || 0;
					$('#astm-export-bar').css('width', pct+'%');
					$('#astm-export-message').text(d.message);
					if(d.done){
						call('astm_export_finalize', {state: JSON.stringify(d.state)}, function(x){
							$('#astm-export-bar').css('width','100%');
							log(x.message);
							$('#astm-export-status').html('<p class="astm-good"><a class="button button-primary" href="'+x.download+'">Download migration ZIP</a></p>');
							$('#astm-export').prop('disabled',false);
						}, function(e){ log('FINALIZE ERROR: '+e); $('#astm-export').prop('disabled',false); });
					}else{
						if(d.logs){ d.logs.forEach(log); }
						setTimeout(function(){ astmExportBatch(d.state); }, 50);
					}
				}, function(e){ log('EXPORT ERROR: '+e); $('#astm-export').prop('disabled',false); });
			}

			function setImportProgress(pct){
				pct = Math.max(0, Math.min(100, parseInt(pct || 0, 10)));
				// Display progress in 5% increments as requested, while the server keeps exact progress internally.
				const shown = Math.floor(pct / 5) * 5;
				$('#astm-import-bar').css('width', shown+'%');
				$('#astm-import-percent').text(shown+'%');
			}

			$('#astm-import').on('click', function(){
				const f = $('#astm-package')[0].files[0];
				if(!f){ log('Choose a ZIP package first.'); return; }
				$('#astm-import').prop('disabled',true);
				setImportProgress(0);
				log('Uploading package in 8 MB chunks to avoid PHP POST size limits...');
				astmUploadChunks(f, 0, null);
			});

			function astmUploadChunks(file, offset, uploadToken){
				const chunkSize = 8 * 1024 * 1024;
				const end = Math.min(offset + chunkSize, file.size);
				const blob = file.slice(offset, end);
				const fd = new FormData();
				fd.append('action','astm_import_chunk');
				fd.append('nonce',nonce);
				fd.append('chunk',blob,file.name+'.part');
				fd.append('upload_token',uploadToken || '');
				fd.append('filename',file.name);
				fd.append('offset',String(offset));
				fd.append('total_size',String(file.size));
				$.ajax({url:ajaxurl,type:'POST',data:fd,processData:false,contentType:false}).done(function(r){
					if(!r.success){ log('IMPORT ERROR: '+(r.data||'Unknown error')); $('#astm-import').prop('disabled',false); return; }
					const next = parseInt(r.data.offset || end, 10);
					// Upload progress is shown separately from import progress.
					if(next < file.size){
						setTimeout(function(){ astmUploadChunks(file,next,r.data.upload_token); },30);
					} else {
						log('Upload complete. Validating and extracting ZIP...');
						call('astm_import_upload', {upload_token:r.data.upload_token,filename:file.name,mode:$('#astm-mode').val(),dry_run:$('#astm-dry-run').is(':checked')?'1':'0'}, function(d){
							log(d.message); astmImportBatch(d.token);
						}, function(e){ log('IMPORT ERROR: '+e); $('#astm-import').prop('disabled',false); });
					}
				}).fail(function(xhr){ log('IMPORT ERROR: '+(xhr.responseText || 'Chunk upload failed')); $('#astm-import').prop('disabled',false); });
			}

			function astmImportBatch(token){
				call('astm_import_batch', {token: token}, function(d){
					setImportProgress(d.percent || 0);
					$('#astm-import-message').text(d.message);
					if(d.logs){ d.logs.forEach(log); }
					if(d.done){
						setImportProgress(100);
						log('IMPORT FINISHED. '+d.summary);
						$('#astm-import').prop('disabled',false);
					}else{
						setTimeout(function(){ astmImportBatch(token); }, 100);
					}
				}, function(e){ log('IMPORT ERROR: '+e); $('#astm-import').prop('disabled',false); });
			}
		});
		</script>
		<?php
	}

	public function ajax_save_config() {
		if ( ! $this->nonce_ok() ) {
			wp_send_json_error( 'Permission or nonce check failed.', 403 );
		}

		$config = $this->config();

		$lines = static function( $value ) {
			$value = is_string( $value ) ? $value : '';
			$value = preg_split( '/\r\n|\r|\n/', $value );
			$value = array_map( 'trim', $value );
			$value = array_filter( $value, static function( $v ) { return '' !== $v; } );
			return array_values( array_unique( $value ) );
		};

		$config['metadata_extra_keys']   = $lines( wp_unslash( $_POST['extra_meta'] ?? '' ) );
		$config['metadata_exclude_keys'] = $lines( wp_unslash( $_POST['exclude_meta'] ?? '' ) );
		$config['reply_post_types']      = $lines( wp_unslash( $_POST['replies'] ?? '' ) );

		update_option( self::OPTION_CONFIG, $config, false );
		wp_send_json_success( array( 'message' => 'Configuration saved.' ) );
	}

	private function temp_root( $token ) {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'astm-private';
		if ( ! wp_mkdir_p( $base ) ) {
			return false;
		}
		$ht = trailingslashit( $base ) . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			@file_put_contents( $ht, "Deny from all\n" );
		}
		$index = trailingslashit( $base ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n" );
		}
		$dir = trailingslashit( $base ) . sanitize_file_name( $token );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		return $dir;
	}

	private function write_jsonl( $file, $row ) {
		$dir = dirname( $file );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$line = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false !== file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	private function read_jsonl_batch( $file, $byte_offset, $limit ) {
		$out = array();
		if ( ! is_readable( $file ) ) {
			return array( 'rows' => $out, 'next' => 0, 'eof' => true, 'lines' => 0 );
		}

		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			return array( 'rows' => $out, 'next' => $byte_offset, 'eof' => true, 'lines' => 0 );
		}

		$byte_offset = max( 0, (int) $byte_offset );
		if ( $byte_offset > 0 ) {
			if ( 0 !== fseek( $fh, $byte_offset, SEEK_SET ) ) {
				fclose( $fh );
				return array( 'rows' => $out, 'next' => $byte_offset, 'eof' => true, 'lines' => 0 );
			}
		}

		$lines = 0;
		while ( $lines < $limit && ! feof( $fh ) ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$lines++;
			$row = json_decode( trim( $line ), true );
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}

		$next = ftell( $fh );
		$eof  = feof( $fh );
		fclose( $fh );

		return array(
			'rows'  => $out,
			'next'  => false === $next ? $byte_offset : (int) $next,
			'eof'   => $eof,
			'lines' => $lines,
		);
	}


	private function count_jsonl_lines( $file ) {
		if ( ! is_readable( $file ) ) return 0;
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) return 0;
		$count = 0;
		while ( false !== fgets( $fh ) ) {
			$count++;
		}
		fclose( $fh );
		return $count;
	}

	private function source_url() {
		return untrailingslashit( home_url() );
	}

	private function safe_url_replace( $value, $old, $new ) {
		if ( is_string( $value ) && '' !== $old && '' !== $new ) {
			return str_replace( $old, $new, $value );
		}
		return $value;
	}

	private function recursive_replace( $value, $old, $new ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->recursive_replace( $v, $old, $new );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = $this->recursive_replace( $v, $old, $new );
			}
			return $value;
		}
		return $this->safe_url_replace( $value, $old, $new );
	}

	private function metadata_for_post( $post_id ) {
		$config = $this->config();
		$all    = get_post_meta( $post_id );
		$out    = array();

		foreach ( $all as $key => $values ) {
			if ( in_array( $key, (array) $config['metadata_exclude_keys'], true ) && ! in_array( $key, (array) $config['metadata_extra_keys'], true ) ) {
				continue;
			}
			$out[ $key ] = array();
			foreach ( $values as $raw ) {
				$out[ $key ][] = maybe_unserialize( $raw );
			}
		}

		return $out;
	}

	private function post_record( WP_Post $post ) {
		$taxonomies = array();
		$tax_objects = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $tax_objects as $tax ) {
			$terms = wp_get_object_terms( $post->ID, $tax->name, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$taxonomies[ $tax->name ] = array();
			foreach ( $terms as $term ) {
				$taxonomies[ $tax->name ][] = array(
					'term_id'     => (int) $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => (int) $term->parent,
				);
			}
		}

		return array(
			'old_id'             => (int) $post->ID,
			'post_type'          => $post->post_type,
			'post_author'        => (int) $post->post_author,
			'post_date'          => $post->post_date,
			'post_date_gmt'      => $post->post_date_gmt,
			'post_content'       => $post->post_content,
			'post_title'         => $post->post_title,
			'post_excerpt'       => $post->post_excerpt,
			'post_status'        => $post->post_status,
			'comment_status'     => $post->comment_status,
			'ping_status'        => $post->ping_status,
			'post_password'      => $post->post_password,
			'post_name'          => $post->post_name,
			'to_ping'            => $post->to_ping,
			'pinged'             => $post->pinged,
			'post_modified'      => $post->post_modified,
			'post_modified_gmt'  => $post->post_modified_gmt,
			'post_parent'        => (int) $post->post_parent,
			'menu_order'        => (int) $post->menu_order,
			'post_mime_type'    => $post->post_mime_type,
			'guid'               => $post->guid,
			'meta'               => $this->metadata_for_post( $post->ID ),
			'taxonomies'         => $taxonomies,
		);
	}

	private function collect_ids_from_value( $value, &$post_ids, &$user_ids, &$attachment_ids ) {
		$config = $this->config();

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $v ) {
				$lk = strtolower( (string) $key );
				if ( is_numeric( $v ) ) {
					$n = absint( $v );
					if ( $n > 0 && preg_match( '/(attachment|thumbnail|media|file|image)/', $lk ) ) {
						$attachment_ids[ $n ] = $n;
					}
					if ( $n > 0 && preg_match( '/(user|author|agent|assignee|customer)/', $lk ) ) {
						$user_ids[ $n ] = $n;
					}
					if ( $n > 0 && preg_match( '/(^|_)(post|ticket|reply|parent)_?id$/', $lk ) ) {
						$post_ids[ $n ] = $n;
					}
				}
				$this->collect_ids_from_value( $v, $post_ids, $user_ids, $attachment_ids );
			}
		} elseif ( is_object( $value ) ) {
			$this->collect_ids_from_value( get_object_vars( $value ), $post_ids, $user_ids, $attachment_ids );
		} elseif ( is_string( $value ) ) {
			if ( preg_match_all( '/(?:attachment_id|media_id|thumbnail_id|file_id)[\'"\s:=]+([0-9]+)/i', $value, $m ) ) {
				foreach ( $m[1] as $id ) {
					$attachment_ids[ absint( $id ) ] = absint( $id );
				}
			}
		}
	}

	private function collect_attachments_from_post( $post_id, &$attachment_ids ) {
		$children = get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
				'numberposts' => -1,
				'post_status' => 'inherit',
				'fields'      => 'ids',
			)
		);
		foreach ( (array) $children as $id ) {
			$attachment_ids[ absint( $id ) ] = absint( $id );
		}

		$meta = get_post_meta( $post_id );
		foreach ( $meta as $key => $values ) {
			foreach ( $values as $raw ) {
				$this->collect_ids_from_value( maybe_unserialize( $raw ), $dummy_posts, $dummy_users, $attachment_ids );
			}
		}
	}

	private function collect_users_from_post( WP_Post $post, &$user_ids ) {
		if ( $post->post_author ) {
			$user_ids[ (int) $post->post_author ] = (int) $post->post_author;
		}
		$meta = $this->metadata_for_post( $post->ID );
		foreach ( $meta as $key => $values ) {
			foreach ( $values as $value ) {
				if ( in_array( $key, (array) $this->config()['user_meta_keys'], true ) ) {
					if ( is_numeric( $value ) ) {
						$user_ids[ absint( $value ) ] = absint( $value );
					}
				}
			}
		}
	}

	private function attachment_record( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		$file = get_attached_file( $id );
		$url  = wp_get_attachment_url( $id );

		return array(
			'old_id'        => (int) $id,
			'post'          => $this->post_record( $post ),
			'file'          => $file && file_exists( $file ) ? wp_normalize_path( $file ) : '',
			'url'           => $url ? $url : '',
			'basename'      => $file ? wp_basename( $file ) : '',
			'mime'          => get_post_mime_type( $id ),
		);
	}

	private function user_record( $id ) {
		$user = get_userdata( $id );
		if ( ! $user ) {
			return false;
		}

		$meta = get_user_meta( $id );
		$safe_meta = array();

		foreach ( $meta as $key => $values ) {
			$lk = strtolower( $key );
			if ( preg_match( '/pass|password|token|secret|api[_-]?key|auth[_-]?key|session|cookie|oauth/i', $lk ) ) {
				continue;
			}
			$safe_meta[ $key ] = array_map( 'maybe_unserialize', $values );
		}

		return array(
			'old_id'       => (int) $id,
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'user_nicename' => $user->user_nicename,
			'user_url'     => $user->user_url,
			'display_name' => $user->display_name,
			'first_name'   => get_user_meta( $id, 'first_name', true ),
			'last_name'    => get_user_meta( $id, 'last_name', true ),
			'roles'        => array_values( (array) $user->roles ),
			'meta'         => $safe_meta,
		);
	}

	public function ajax_export_start() {
		if ( ! $this->nonce_ok() ) {
			wp_send_json_error( 'Permission or nonce check failed.', 403 );
		}

		$token = wp_generate_password( 24, false, false );
		$root  = $this->temp_root( $token );

		if ( ! $root ) {
			wp_send_json_error( 'Could not create a private migration directory.', 500 );
		}

		$state = array(
			'token'          => $token,
			'root'           => $root,
			'phase'          => 'tickets',
			'offset'         => 0,
			'ticket_offset'  => 0,
			'reply_offset'   => 0,
			'user_offset'    => 0,
			'term_offset'    => 0,
			'attachment_offset' => 0,
			'ticket_ids'     => array(),
			'reply_ids'      => array(),
			'user_ids'       => array(),
			'attachment_ids' => array(),
			'term_keys'      => array(),
			'counts'         => array(
				'tickets' => 0,
				'replies' => 0,
				'users' => 0,
				'terms' => 0,
				'attachments' => 0,
			),
			'source_url'     => $this->source_url(),
			'started'        => current_time( 'mysql', true ),
		);

		file_put_contents( trailingslashit( $root ) . 'state.json', wp_json_encode( $state ) );

		wp_send_json_success(
			array(
				'message' => 'Export workspace created.',
				'state'   => $state,
			)
		);
	}

	private function export_query_ids( $post_type, $offset, $limit ) {
		return get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                => 'ids',
				'no_found_rows'         => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	public function ajax_export_batch() {
		if ( ! $this->nonce_ok() ) {
			wp_send_json_error( 'Permission or nonce check failed.', 403 );
		}

		$state = json_decode( wp_unslash( $_POST['state'] ?? '' ), true );
		if ( ! is_array( $state ) || empty( $state['root'] ) || empty( $state['token'] ) ) {
			wp_send_json_error( 'Invalid export state.', 400 );
		}

		$root = $this->temp_root( $state['token'] );
		if ( ! $root || ! is_dir( $root ) ) {
			wp_send_json_error( 'Export workspace no longer exists.', 400 );
		}

		$config = $this->config();
		$logs = array();

		switch ( $state['phase'] ) {
			case 'tickets':
				$ids = $this->export_query_ids( $config['ticket_post_type'], $state['ticket_offset'], self::BATCH );
				foreach ( $ids as $id ) {
					$post = get_post( $id );
					if ( ! $post ) continue;
					$this->write_jsonl( trailingslashit( $root ) . 'tickets.jsonl', $this->post_record( $post ) );
					$state['ticket_ids'][ $id ] = $id;
					$state['counts']['tickets']++;
					$this->collect_users_from_post( $post, $state['user_ids'] );
					$this->collect_attachments_from_post( $id, $state['attachment_ids'] );

					$replies = get_posts(
						array(
							'post_type'      => $config['reply_post_types'],
							'post_parent'    => $id,
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
						)
					);
					foreach ( $replies as $reply_id ) {
						$state['reply_ids'][ $reply_id ] = $reply_id;
						$this->collect_attachments_from_post( $reply_id, $state['attachment_ids'] );
					}
				}
				$state['ticket_offset'] += count( $ids );
				if ( count( $ids ) < self::BATCH ) {
					$state['phase'] = 'replies';
					$state['reply_offset'] = 0;
				}
				$logs[] = 'Exported ' . count( $ids ) . ' ticket(s).';
				break;

			case 'replies':
				$reply_ids = array_values( $state['reply_ids'] );
				$chunk = array_slice( $reply_ids, $state['reply_offset'], self::BATCH );
				foreach ( $chunk as $id ) {
					$post = get_post( $id );
					if ( ! $post ) continue;
					$this->write_jsonl( trailingslashit( $root ) . 'replies.jsonl', $this->post_record( $post ) );
					$state['counts']['replies']++;
					$this->collect_users_from_post( $post, $state['user_ids'] );
				}
				$state['reply_offset'] += count( $chunk );
				if ( $state['reply_offset'] >= count( $reply_ids ) ) {
					$state['phase'] = 'users';
					$state['user_offset'] = 0;
				}
				$logs[] = 'Exported ' . count( $chunk ) . ' reply record(s).';
				break;

			case 'users':
				$user_ids = array_values( $state['user_ids'] );
				$chunk = array_slice( $user_ids, $state['user_offset'], self::BATCH );
				foreach ( $chunk as $id ) {
					$record = $this->user_record( $id );
					if ( $record ) {
						$this->write_jsonl( trailingslashit( $root ) . 'users.jsonl', $record );
						$state['counts']['users']++;
					}
				}
				$state['user_offset'] += count( $chunk );
				if ( $state['user_offset'] >= count( $user_ids ) ) {
					$state['phase'] = 'attachments';
					$state['attachment_offset'] = 0;
				}
				$logs[] = 'Exported ' . count( $chunk ) . ' user record(s).';
				break;

			case 'attachments':
				$ids = array_values( $state['attachment_ids'] );
				$chunk = array_slice( $ids, $state['attachment_offset'], self::BATCH );
				foreach ( $chunk as $id ) {
					$record = $this->attachment_record( $id );
					if ( ! $record ) continue;
					$this->write_jsonl( trailingslashit( $root ) . 'attachments.jsonl', $record );

					if ( ! empty( $record['file'] ) && is_readable( $record['file'] ) ) {
						$uploads = wp_upload_dir();
						$basedir = wp_normalize_path( $uploads['basedir'] );
						$file   = wp_normalize_path( $record['file'] );
						if ( 0 === strpos( $file, trailingslashit( $basedir ) ) ) {
							$relative = ltrim( substr( $file, strlen( $basedir ) ), '/\\' );
							$dest = trailingslashit( $root ) . 'uploads/' . $relative;
							wp_mkdir_p( dirname( $dest ) );
							if ( ! file_exists( $dest ) ) {
								@copy( $file, $dest );
							}
						}
					}
					$state['counts']['attachments']++;
				}
				$state['attachment_offset'] += count( $chunk );
				if ( $state['attachment_offset'] >= count( $ids ) ) {
					$state['phase'] = 'finalize';
				}
				$logs[] = 'Prepared ' . count( $chunk ) . ' attachment(s).';
				break;

			case 'finalize':
				break;
		}

		$done = ( 'finalize' === $state['phase'] );
		$percent = $done ? 100 : $this->export_percent( $state );

		file_put_contents( trailingslashit( $root ) . 'state.json', wp_json_encode( $state ) );

		wp_send_json_success(
			array(
				'state'   => $state,
				'done'    => $done,
				'percent' => $percent,
				'message' => $done ? 'Export data prepared. Building ZIP...' : 'Processing ' . $state['phase'] . '...',
				'logs'    => $logs,
			)
		);
	}

	private function export_percent( $state ) {
		$phases = array( 'tickets' => 10, 'replies' => 25, 'users' => 40, 'attachments' => 85, 'finalize' => 100 );
		return isset( $phases[ $state['phase'] ] ) ? $phases[ $state['phase'] ] : 90;
	}

	public function ajax_export_finalize() {
		if ( ! $this->nonce_ok() ) {
			wp_send_json_error( 'Permission or nonce check failed.', 403 );
		}

		$state = json_decode( wp_unslash( $_POST['state'] ?? '' ), true );
		if ( ! is_array( $state ) || empty( $state['root'] ) || empty( $state['token'] ) ) {
			wp_send_json_error( 'Invalid export state.', 400 );
		}

		$root = $this->temp_root( $state['token'] );
		if ( ! $root ) {
			wp_send_json_error( 'Export workspace unavailable.', 400 );
		}

		$manifest = array(
			'schema'          => 1,
			'plugin_version'  => self::VERSION,
			'created_utc'     => gmdate( 'c' ),
			'source_url'      => $state['source_url'],
			'wordpress'       => get_bloginfo( 'version' ),
			'php'             => PHP_VERSION,
			'awesome_support' => defined( 'WPAS_VERSION' ) ? WPAS_VERSION : ( defined( 'WPAS_PLUGIN_VERSION' ) ? WPAS_PLUGIN_VERSION : 'unknown' ),
			'config'          => $this->config(),
			'counts'          => $state['counts'],
			'files'           => array(
				'tickets.jsonl',
				'replies.jsonl',
				'users.jsonl',
				'attachments.jsonl',
			),
			'warning'         => 'User passwords and secrets are not included. Plugin settings and unrelated data are not included.',
		);

		file_put_contents( trailingslashit( $root ) . 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( 'PHP ZipArchive is required to create the migration package.', 500 );
		}

		$upload = wp_upload_dir();
		$zip_name = 'awesome-support-migration-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.zip';
		$zip_path = trailingslashit( $upload['basedir'] ) . $zip_name;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_send_json_error( 'Could not create ZIP file.', 500 );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$path = wp_normalize_path( $file->getPathname() );
				$relative = ltrim( substr( $path, strlen( wp_normalize_path( $root ) ) ), '/\\' );
				if ( 'state.json' === $relative ) continue;
				$zip->addFile( $file->getPathname(), $relative );
			}
		}

		$zip->close();

		$download = wp_nonce_url(
			admin_url( 'admin-post.php?action=astm_download&file=' . rawurlencode( $zip_name ) ),
			'astm_download_' . $zip_name
		);

		add_action( 'admin_post_astm_download', array( $this, 'download_zip' ) );

		// The action registration above is normally too late for the current request,
		// but the plugin also registers it below during construction on subsequent requests.
		wp_send_json_success(
			array(
				'message'  => 'Migration ZIP created successfully.',
				'download' => $download,
				'summary'  => wp_json_encode( $state['counts'] ),
			)
		);
	}

	public function download_zip() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'astm' ), 403 );
		}
		$file = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
		if ( ! $file || ! check_admin_referer( 'astm_download_' . $file ) ) {
			wp_die( esc_html__( 'Invalid download request.', 'astm' ), 400 );
		}
		$upload = wp_upload_dir();
		$path = trailingslashit( $upload['basedir'] ) . $file;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Package not found.', 'astm' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	public function ajax_import_chunk() {
		if ( ! $this->nonce_ok() ) wp_send_json_error( 'Permission or nonce check failed.', 403 );
		if ( empty( $_FILES['chunk'] ) || ! empty( $_FILES['chunk']['error'] ) ) wp_send_json_error( 'No valid upload chunk was received.', 400 );
		$filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
		$offset = absint( $_POST['offset'] ?? 0 );
		$total = absint( $_POST['total_size'] ?? 0 );
		$token = sanitize_key( wp_unslash( $_POST['upload_token'] ?? '' ) );
		if ( ! $filename || ! preg_match( '/\.zip$/i', $filename ) ) wp_send_json_error( 'Only ZIP packages are accepted.', 400 );
		if ( $total <= 0 || $total > 2 * 1024 * 1024 * 1024 ) wp_send_json_error( 'Package exceeds the 2 GB safety limit.', 400 );
		if ( $offset > $total ) wp_send_json_error( 'Invalid upload offset.', 400 );
		if ( ! $token ) $token = strtolower( wp_generate_password( 32, false, false ) );
		$root = $this->temp_root( $token );
		if ( ! $root ) wp_send_json_error( 'Could not create upload workspace.', 500 );
		$path = trailingslashit( $root ) . 'package.zip';
		$current = file_exists( $path ) ? (int) filesize( $path ) : 0;
		if ( $current !== $offset ) wp_send_json_error( 'Upload sequence mismatch.', 409 );
		$in = fopen( $_FILES['chunk']['tmp_name'], 'rb' );
		$out = fopen( $path, 'ab' );
		if ( ! $in || ! $out ) { if ( $in ) fclose($in); if ($out) fclose($out); wp_send_json_error( 'Could not save upload chunk.', 500 ); }
		$copied = stream_copy_to_stream( $in, $out ); fclose($in); fclose($out);
		if ( false === $copied ) wp_send_json_error( 'Could not save upload chunk.', 500 );
		$new_size = (int) filesize( $path );
		if ( $new_size > $total ) { @unlink($path); wp_send_json_error( 'Uploaded data exceeded declared size.', 400 ); }
		wp_send_json_success( array( 'upload_token'=>$token, 'offset'=>$new_size, 'complete'=>$new_size===$total ) );
	}

	public function ajax_import_upload() {
		if ( ! $this->nonce_ok() ) {
			wp_send_json_error( 'Permission or nonce check failed.', 403 );
		}

		$token = sanitize_key( wp_unslash( $_POST['upload_token'] ?? '' ) );
		if ( ! $token ) wp_send_json_error( 'Upload token is missing.', 400 );
		$root = $this->temp_root( $token );
		if ( ! $root ) wp_send_json_error( 'Could not create import workspace.', 500 );
		$file_path = trailingslashit( $root ) . 'package.zip';
		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) wp_send_json_error( 'Uploaded package was not found.', 400 );
		$file = array( 'tmp_name'=>$file_path, 'name'=>sanitize_file_name( wp_unslash( $_POST['filename'] ?? 'package.zip' ) ) );
		if ( filesize( $file_path ) > 2 * 1024 * 1024 * 1024 ) wp_send_json_error( 'Package exceeds the 2 GB safety limit.', 400 );
		$check = wp_check_filetype_and_ext( $file_path, $file['name'], array( 'zip' => 'application/zip' ) );
		if ( empty( $check['ext'] ) || 'zip' !== strtolower( $check['ext'] ) ) wp_send_json_error( 'The uploaded package is not a valid ZIP file.', 400 );
		if ( ! $root ) {
			wp_send_json_error( 'Could not create import workspace.', 500 );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file['tmp_name'] ) ) {
			wp_send_json_error( 'The uploaded file is not a valid ZIP archive.', 400 );
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		if ( false === $manifest_raw ) {
			$zip->close();
			wp_send_json_error( 'Invalid package: manifest.json is missing.', 400 );
		}
		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) || empty( $manifest['schema'] ) ) {
			$zip->close();
			wp_send_json_error( 'Invalid migration manifest.', 400 );
		}
		if ( (int) $manifest['schema'] !== 1 ) {
			$zip->close();
			wp_send_json_error( 'Unsupported migration schema.', 400 );
		}

		// Prevent Zip Slip: inspect every archive path before extraction.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = isset( $stat['name'] ) ? str_replace( '\\', '/', $stat['name'] ) : '';
			if ( '' === $name || 0 === strpos( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
				$zip->close();
				wp_send_json_error( 'Unsafe ZIP path detected.', 400 );
			}
		}

		if ( ! $zip->extractTo( $root ) ) {
			$zip->close();
			wp_send_json_error( 'Could not extract the migration package.', 500 );
		}
		$zip->close();

		$state = array(
			'token'       => $token,
			'root'        => $root,
			'phase'       => 'users',
			'offset'      => 0,
			'processed'   => 0,
			'term_file'   => 'tickets.jsonl',
			'mode'        => in_array( sanitize_key( $_POST['mode'] ?? 'skip' ), array( 'skip', 'update', 'duplicate' ), true ) ? sanitize_key( $_POST['mode'] ) : 'skip',
			'dry_run'     => ! empty( $_POST['dry_run'] ),
			'manifest'    => $manifest,
			'totals'      => array(
				'users'       => $this->count_jsonl_lines( trailingslashit( $root ) . 'users.jsonl' ),
				'tickets'     => $this->count_jsonl_lines( trailingslashit( $root ) . 'tickets.jsonl' ),
				'replies'     => $this->count_jsonl_lines( trailingslashit( $root ) . 'replies.jsonl' ),
				'attachments' => $this->count_jsonl_lines( trailingslashit( $root ) . 'attachments.jsonl' ),
			),
			'user_map'    => array(),
			'term_map'    => array(),
			'post_map'    => array(),
			'attachment_map' => array(),
			'summary'     => array(
				'users_created' => 0,
				'users_matched' => 0,
				'terms_created' => 0,
				'tickets_created' => 0,
				'tickets_updated' => 0,
				'tickets_skipped' => 0,
				'replies_created' => 0,
				'replies_updated' => 0,
				'replies_skipped' => 0,
				'attachments_created' => 0,
				'attachments_updated' => 0,
				'attachments_skipped' => 0,
				'errors' => 0,
				'warnings' => 0,
			),
			'log' => array(),
		);

		file_put_contents( trailingslashit( $root ) . 'import-state.json', wp_json_encode( $state ) );

		wp_send_json_success(
			array(
				'message' => 'Package validated and extracted. Source: ' . $manifest['source_url'],
				'token'   => $token,
			)
		);
	}

	private function add_log( &$state, $message, $level = 'info' ) {
		$state['log'][] = '[' . strtoupper( $level ) . '] ' . $message;
		if ( count( $state['log'] ) > self::MAX_LOG ) {
			$state['log'] = array_slice( $state['log'], -self::MAX_LOG );
		}
	}

	private function find_existing_user( $record ) {
		if ( ! empty( $record['user_email'] ) ) {
			$user = get_user_by( 'email', sanitize_email( $record['user_email'] ) );
			if ( $user ) return $user;
		}
		if ( ! empty( $record['user_login'] ) ) {
			$user = get_user_by( 'login', $record['user_login'] );
			if ( $user ) return $user;
		}
		return false;
	}

	private function import_user( $record, &$state ) {
		$old_id = absint( $record['old_id'] );
		if ( isset( $state['user_map'][ $old_id ] ) ) {
			return (int) $state['user_map'][ $old_id ];
		}

		$existing = $this->find_existing_user( $record );
		if ( $existing ) {
			$state['user_map'][ $old_id ] = (int) $existing->ID;
			$state['summary']['users_matched']++;
			return (int) $existing->ID;
		}

		if ( $state['dry_run'] ) {
			$state['user_map'][ $old_id ] = 0;
			return 0;
		}

		$login = sanitize_user( $record['user_login'], true );
		if ( '' === $login ) {
			$login = 'migrated_user_' . $old_id;
		}
		$base = $login;
		$i = 1;
		while ( username_exists( $login ) ) {
			$login = $base . '_' . $i++;
		}

		$email = sanitize_email( $record['user_email'] );
		if ( $email && email_exists( $email ) ) {
			$existing = get_user_by( 'email', $email );
			$state['user_map'][ $old_id ] = (int) $existing->ID;
			$state['summary']['users_matched']++;
			return (int) $existing->ID;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $email,
				'user_nicename' => sanitize_title( $record['user_nicename'] ?: $login ),
				'user_url'     => esc_url_raw( $record['user_url'] ?? '' ),
				'display_name' => sanitize_text_field( $record['display_name'] ?? $login ),
				'first_name'   => sanitize_text_field( $record['first_name'] ?? '' ),
				'last_name'    => sanitize_text_field( $record['last_name'] ?? '' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$state['summary']['errors']++;
			$this->add_log( $state, 'Could not create user ' . $old_id . ': ' . $user_id->get_error_message(), 'error' );
			return 0;
		}

		// Never grant a role that the destination does not have.
		$wp_user = new WP_User( $user_id );
		foreach ( (array) $record['roles'] as $role ) {
			if ( get_role( $role ) ) {
				$wp_user->add_role( $role );
			}
		}

		foreach ( (array) $record['meta'] as $key => $values ) {
			foreach ( (array) $values as $value ) {
				if ( preg_match( '/pass|password|token|secret|api[_-]?key|auth[_-]?key|session|cookie|oauth/i', $key ) ) {
					continue;
				}
				update_user_meta( $user_id, sanitize_key( $key ), $this->remap_value( $value, $state, $key ) );
			}
		}

		$state['user_map'][ $old_id ] = (int) $user_id;
		$state['summary']['users_created']++;
		return (int) $user_id;
	}

	private function import_terms_from_record( $record, &$state ) {
		foreach ( (array) $record['taxonomies'] as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$state['summary']['warnings']++;
				$this->add_log( $state, 'Taxonomy "' . $taxonomy . '" does not exist on destination; terms skipped.', 'warning' );
				continue;
			}
			foreach ( $terms as $term ) {
				$key = $taxonomy . ':' . absint( $term['term_id'] );
				if ( isset( $state['term_map'][ $key ] ) ) continue;

				$existing = term_exists( $term['slug'], $taxonomy );
				if ( $existing ) {
					$state['term_map'][ $key ] = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
					continue;
				}

				if ( $state['dry_run'] ) {
					$state['term_map'][ $key ] = 0;
					continue;
				}

				$parent_new = 0;
				if ( ! empty( $term['parent'] ) ) {
					$pkey = $taxonomy . ':' . absint( $term['parent'] );
					$parent_new = isset( $state['term_map'][ $pkey ] ) ? absint( $state['term_map'][ $pkey ] ) : 0;
				}

				$insert = wp_insert_term(
					$term['name'],
					$taxonomy,
					array(
						'slug'        => sanitize_title( $term['slug'] ),
						'description' => wp_kses_post( $term['description'] ?? '' ),
						'parent'      => $parent_new,
					)
				);

				if ( is_wp_error( $insert ) ) {
					$state['summary']['errors']++;
					$this->add_log( $state, 'Term import failed for ' . $taxonomy . '/' . $term['name'] . ': ' . $insert->get_error_message(), 'error' );
					continue;
				}

				$state['term_map'][ $key ] = (int) $insert['term_id'];
				$state['summary']['terms_created']++;
			}
		}
	}

	private function find_existing_post( $record, $mode ) {
		if ( 'duplicate' === $mode ) return false;

		// First try an exact old-ID marker generated by this plugin.
		$q = new WP_Query(
			array(
				'post_type'      => $record['post_type'],
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_astm_source_id',
				'meta_value'     => absint( $record['old_id'] ),
				'no_found_rows'   => true,
			)
		);
		if ( ! empty( $q->posts[0] ) ) {
			return (int) $q->posts[0];
		}

		// For tickets/replies not previously migrated, use a conservative fingerprint.
		$q = new WP_Query(
			array(
				'post_type'      => $record['post_type'],
				'post_status'    => 'any',
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'title'          => $record['post_title'],
				'date_query'     => array(
					array(
						'after'     => $record['post_date'],
						'before'    => $record['post_date'],
						'inclusive' => true,
					),
				),
				'no_found_rows' => true,
			)
		);

		return ! empty( $q->posts[0] ) ? (int) $q->posts[0] : false;
	}

	private function remap_value( $value, &$state, $key = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->remap_value( $v, $state, $k );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = $this->remap_value( $v, $state, $k );
			}
			return $value;
		}

		if ( is_numeric( $value ) ) {
			$n = absint( $value );
			$lk = strtolower( (string) $key );

			if ( preg_match( '/(attachment|thumbnail|media|file|image)/', $lk ) && isset( $state['attachment_map'][ $n ] ) ) {
				return (int) $state['attachment_map'][ $n ];
			}
			if ( preg_match( '/(user|author|agent|assignee|customer)/', $lk ) && isset( $state['user_map'][ $n ] ) ) {
				return (int) $state['user_map'][ $n ];
			}
			if ( preg_match( '/(^|_)(post|ticket|reply|parent)_?id$/', $lk ) && isset( $state['post_map'][ $n ] ) ) {
				return (int) $state['post_map'][ $n ];
			}
		}

		return $value;
	}

	private function replace_urls_and_ids( $value, &$state ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->replace_urls_and_ids( $v, $state );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = $this->replace_urls_and_ids( $v, $state );
			}
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$source = untrailingslashit( $state['manifest']['source_url'] );
		$destination = untrailingslashit( home_url() );
		if ( '' !== $source && $source !== $destination ) {
			$value = str_replace( $source, $destination, $value );
		}

		// Replace old attachment URLs with the destination URLs when available.
		foreach ( (array) $state['attachment_map'] as $old_id => $new_id ) {
			$old_url = wp_get_attachment_url( $old_id );
			$new_url = wp_get_attachment_url( $new_id );
			if ( $old_url && $new_url ) {
				$value = str_replace( $old_url, $new_url, $value );
			}
		}
		return $value;
	}

	private function import_post_record( $record, &$state, $is_reply = false ) {
		$old_id = absint( $record['old_id'] );
		if ( isset( $state['post_map'][ $old_id ] ) ) {
			return (int) $state['post_map'][ $old_id ];
		}

		$existing = $this->find_existing_post( $record, $state['mode'] );

		if ( $existing && 'skip' === $state['mode'] ) {
			$state['post_map'][ $old_id ] = $existing;
			$state['summary'][ $is_reply ? 'replies_skipped' : 'tickets_skipped' ]++;
			return $existing;
		}

		if ( $state['dry_run'] ) {
			$state['post_map'][ $old_id ] = $existing ? $existing : 0;
			return $existing ? $existing : 0;
		}

		$author = isset( $state['user_map'][ absint( $record['post_author'] ) ] ) ? absint( $state['user_map'][ absint( $record['post_author'] ) ] ) : 0;
		$parent = isset( $state['post_map'][ absint( $record['post_parent'] ) ] ) ? absint( $state['post_map'][ absint( $record['post_parent'] ) ] ) : 0;

		$postarr = array(
			'post_type'        => sanitize_key( $record['post_type'] ),
			'post_author'      => $author,
			'post_date'        => sanitize_text_field( $record['post_date'] ),
			'post_date_gmt'    => sanitize_text_field( $record['post_date_gmt'] ),
			'post_content'     => $this->replace_urls_and_ids( $record['post_content'], $state ),
			'post_title'       => $this->replace_urls_and_ids( $record['post_title'], $state ),
			'post_excerpt'     => $this->replace_urls_and_ids( $record['post_excerpt'], $state ),
			'post_status'      => sanitize_key( $record['post_status'] ),
			'comment_status'   => sanitize_key( $record['comment_status'] ),
			'ping_status'     => sanitize_key( $record['ping_status'] ),
			'post_password'    => $record['post_password'],
			'post_name'       => sanitize_title( $record['post_name'] ),
			'post_modified'   => sanitize_text_field( $record['post_modified'] ),
			'post_modified_gmt' => sanitize_text_field( $record['post_modified_gmt'] ),
			'post_parent'      => $parent,
			'menu_order'       => absint( $record['menu_order'] ),
			'post_mime_type'   => sanitize_text_field( $record['post_mime_type'] ),
		);

		if ( $existing && 'update' === $state['mode'] ) {
			$postarr['ID'] = $existing;
			$new_id = wp_update_post( wp_slash( $postarr ), true );
			$action = 'updated';
		} else {
			$new_id = wp_insert_post( wp_slash( $postarr ), true );
			$action = 'created';
		}

		if ( is_wp_error( $new_id ) ) {
			$state['summary']['errors']++;
			$this->add_log( $state, 'Post ' . $old_id . ' failed: ' . $new_id->get_error_message(), 'error' );
			return 0;
		}

		$new_id = (int) $new_id;
		$state['post_map'][ $old_id ] = $new_id;

		update_post_meta( $new_id, '_astm_source_id', $old_id );
		update_post_meta( $new_id, '_astm_source_url', esc_url_raw( $state['manifest']['source_url'] ) );

		if ( $is_reply ) {
			$state['summary'][ 'replies_' . $action ]++;
		} else {
			$state['summary'][ 'tickets_' . $action ]++;
		}

		// Restore taxonomies.
		foreach ( (array) $record['taxonomies'] as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) continue;
			$new_terms = array();
			foreach ( $terms as $term ) {
				$key = $taxonomy . ':' . absint( $term['term_id'] );
				if ( isset( $state['term_map'][ $key ] ) && $state['term_map'][ $key ] ) {
					$new_terms[] = (int) $state['term_map'][ $key ];
				}
			}
			if ( $new_terms ) {
				wp_set_object_terms( $new_id, $new_terms, $taxonomy, false );
			}
		}

		// Restore every non-excluded post-meta value, including serialized arrays.
		foreach ( (array) $record['meta'] as $key => $values ) {
			if ( in_array( $key, (array) $this->config()['metadata_exclude_keys'], true ) && ! in_array( $key, (array) $this->config()['metadata_extra_keys'], true ) ) {
				continue;
			}
			delete_post_meta( $new_id, $key );
			foreach ( (array) $values as $value ) {
				$value = $this->remap_value( $value, $state, $key );
				$value = $this->replace_urls_and_ids( $value, $state );
				add_post_meta( $new_id, $key, $value );
			}
		}

		return $new_id;
	}

	private function import_attachments_batch( &$state ) {
		$file = trailingslashit( $state['root'] ) . 'attachments.jsonl';
		$read = $this->read_jsonl_batch( $file, $state['offset'], self::BATCH );

		foreach ( $read['rows'] as $record ) {
			$old_id = absint( $record['old_id'] );
			$existing = $this->find_existing_post( $record['post'], $state['mode'] );

			if ( $existing && 'skip' === $state['mode'] ) {
				$state['attachment_map'][ $old_id ] = $existing;
				$state['summary']['attachments_skipped']++;
				continue;
			}

			if ( $state['dry_run'] ) {
				$state['attachment_map'][ $old_id ] = $existing ? $existing : 0;
				continue;
			}

			$source_file = '';
			if ( ! empty( $record['file'] ) ) {
				$uploads = wp_upload_dir();
				$relative = '';
				$source_old_uploads = wp_normalize_path( wp_upload_dir()['basedir'] );
				$old_file = wp_normalize_path( $record['file'] );
				if ( 0 === strpos( $old_file, trailingslashit( $source_old_uploads ) ) ) {
					$relative = ltrim( substr( $old_file, strlen( trailingslashit( $source_old_uploads ) ) ), '/\\' );
				}
				if ( $relative ) {
					$source_file = trailingslashit( $state['root'] ) . 'uploads/' . $relative;
				}
			}

			if ( $source_file && is_readable( $source_file ) ) {
				$uploads = wp_upload_dir();
				$target = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], wp_basename( $source_file ) );
				wp_mkdir_p( dirname( $target ) );
				if ( ! @copy( $source_file, $target ) ) {
					$state['summary']['errors']++;
					$this->add_log( $state, 'Could not copy attachment file: ' . $record['basename'], 'error' );
					continue;
				}
				$filename = $target;
			} else {
				$state['summary']['warnings']++;
				$this->add_log( $state, 'Attachment file unavailable; metadata will still be imported for old attachment #' . $old_id, 'warning' );
				$filename = '';
			}

			$post = $record['post'];
			$post['post_parent'] = ! empty( $post['post_parent'] ) && isset( $state['post_map'][ absint( $post['post_parent'] ) ] ) ? $state['post_map'][ absint( $post['post_parent'] ) ] : 0;

			if ( $filename ) {
				$attachment = array(
					'post_mime_type' => $record['mime'] ?: 'application/octet-stream',
					'post_title'     => $post['post_title'],
					'post_content'   => $post['post_content'],
					'post_status'    => 'inherit',
					'post_parent'    => absint( $post['post_parent'] ),
				);
				$new_id = media_handle_sideload(
					array(
						'name'     => wp_basename( $filename ),
						'tmp_name' => $filename,
					),
					absint( $post['post_parent'] ),
					$attachment['post_title']
				);

				// media_handle_sideload may delete/move the temp file; if it fails, report it.
				if ( is_wp_error( $new_id ) ) {
					$state['summary']['errors']++;
					$this->add_log( $state, 'Attachment import failed #' . $old_id . ': ' . $new_id->get_error_message(), 'error' );
					continue;
				}
				$new_id = (int) $new_id;
			} else {
				$new_id = wp_insert_post(
					wp_slash(
						array(
							'post_type'      => 'attachment',
							'post_title'     => $post['post_title'],
							'post_content'   => $post['post_content'],
							'post_status'    => 'inherit',
							'post_parent'    => absint( $post['post_parent'] ),
							'post_mime_type' => $record['mime'],
						)
					),
					true
				);
				if ( is_wp_error( $new_id ) ) {
					$state['summary']['errors']++;
					$this->add_log( $state, 'Attachment placeholder failed #' . $old_id . ': ' . $new_id->get_error_message(), 'error' );
					continue;
				}
				$new_id = (int) $new_id;
			}

			$state['attachment_map'][ $old_id ] = $new_id;
			$state['summary']['attachments_created']++;

			// Restore attachment meta, remapping IDs.
			foreach ( (array) $post['meta'] as $key => $values ) {
				if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) continue;
				delete_post_meta( $new_id, $key );
				foreach ( (array) $values as $value ) {
					$value = $this->remap_value( $value, $state, $key );
					$value = $this->replace_urls_and_ids( $value, $state );
					add_post_meta( $new_id, $key, $value );
				}
			}
		}

		$state['offset'] = $read['next'];
		$state['processed'] = isset( $state['processed'] ) ? $state['processed'] + (int) $read['lines'] : (int) $read['lines'];
		return $read['eof'];
	}

	public function ajax_import_batch() {
		if ( ! $this->nonce_ok() ) {
			wp_send_json_error( 'Permission or nonce check failed.', 403 );
		}

		$token = sanitize_key( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! $token ) {
			wp_send_json_error( 'Import token is missing.', 400 );
		}

		$root = $this->temp_root( $token );
		$state_file = $root ? trailingslashit( $root ) . 'import-state.json' : '';
		if ( ! $root || ! is_dir( $root ) || ! is_readable( $state_file ) ) {
			wp_send_json_error( 'Import workspace or state no longer exists.', 400 );
		}

		$raw = file_get_contents( $state_file );
		$state = json_decode( $raw, true );
		if ( ! is_array( $state ) || empty( $state['token'] ) || $state['token'] !== $token ) {
			wp_send_json_error( 'Invalid server-side import state.', 400 );
		}

		$logs_before = count( (array) $state['log'] );
		$done = false;

		switch ( $state['phase'] ) {
			case 'users':
				$read = $this->read_jsonl_batch( trailingslashit( $root ) . 'users.jsonl', $state['offset'], self::BATCH );
				foreach ( $read['rows'] as $record ) {
					$this->import_user( $record, $state );
				}
				$state['offset'] = $read['next'];
				$state['processed'] += (int) $read['lines'];
				if ( $read['eof'] ) {
					$state['phase'] = 'terms';
					$state['offset'] = 0;
					$state['processed'] = 0;
					$state['term_file'] = 'tickets.jsonl';
				}
				break;

			case 'terms':
				// Terms are processed as two independent streams. The old version
				// accidentally restarted tickets.jsonl after switching to replies,
				// which could leave the import permanently around 20%.
				$term_file = ! empty( $state['term_file'] ) ? $state['term_file'] : 'tickets.jsonl';
				$term_path = trailingslashit( $root ) . $term_file;
				$read = $this->read_jsonl_batch( $term_path, $state['offset'], self::BATCH );
				foreach ( $read['rows'] as $record ) {
					$this->import_terms_from_record( $record, $state );
				}
				$state['offset'] = $read['next'];
				$state['processed'] += (int) $read['lines'];
				if ( $read['eof'] ) {
					if ( 'tickets.jsonl' === $term_file ) {
						$state['term_file'] = 'replies.jsonl';
						$state['offset'] = 0;
					} else {
						$state['phase'] = 'tickets';
						$state['offset'] = 0;
						$state['processed'] = 0;
						unset( $state['term_file'] );
					}
				}
				break;

			case 'tickets':
				$read = $this->read_jsonl_batch( trailingslashit( $root ) . 'tickets.jsonl', $state['offset'], self::BATCH );
				foreach ( $read['rows'] as $record ) {
					$this->import_post_record( $record, $state, false );
				}
				$state['offset'] = $read['next'];
				$state['processed'] += (int) $read['lines'];
				if ( $read['eof'] ) {
					$state['phase'] = 'replies';
					$state['offset'] = 0;
					$state['processed'] = 0;
				}
				break;

			case 'replies':
				$read = $this->read_jsonl_batch( trailingslashit( $root ) . 'replies.jsonl', $state['offset'], self::BATCH );
				foreach ( $read['rows'] as $record ) {
					$this->import_post_record( $record, $state, true );
				}
				$state['offset'] = $read['next'];
				$state['processed'] += (int) $read['lines'];
				if ( $read['eof'] ) {
					$state['phase'] = 'attachments';
					$state['offset'] = 0;
					$state['processed'] = 0;
				}
				break;

			case 'attachments':
				if ( $this->import_attachments_batch( $state ) ) {
					$state['phase'] = 'verify';
					$state['offset'] = 0;
				}
				break;

			case 'verify':
				$state['phase'] = 'finished';
				$done = true;
				$this->add_log( $state, 'Verification phase completed. Review the summary before considering the migration finished.' );
				break;

			case 'finished':
				$done = true;
				break;
		}

		$logs = array_slice( (array) $state['log'], $logs_before );
		file_put_contents( $state_file, wp_json_encode( $state ), LOCK_EX );

		$percent = $this->import_percent( $state );
		$summary = array();
		foreach ( (array) $state['summary'] as $k => $v ) {
			$summary[] = $k . '=' . $v;
		}

		wp_send_json_success(
			array(
				'token'   => $token,
				'done'    => $done,
				'percent' => $percent,
				'message' => $done ? 'Import finished.' : 'Processing ' . $state['phase'] . '...',
				'logs'    => $logs,
				'summary' => implode( ', ', $summary ),
			)
		);
	}

	private function import_percent( $state ) {
		$phase = isset( $state['phase'] ) ? $state['phase'] : '';
		$totals = isset( $state['totals'] ) && is_array( $state['totals'] ) ? $state['totals'] : array();

		$start = 0;
		$end   = 0;
		$total = 0;
		$processed = isset( $state['processed'] ) ? max( 0, (int) $state['processed'] ) : 0;

		switch ( $phase ) {
			case 'users':
				$start = 0; $end = 10; $total = (int) ( $totals['users'] ?? 0 ); break;
			case 'terms':
				$start = 10; $end = 20;
				$term_file = ! empty( $state['term_file'] ) ? $state['term_file'] : 'tickets.jsonl';
				$term_total = (int) ( $totals['tickets'] ?? 0 ) + (int) ( $totals['replies'] ?? 0 );
				$total = $term_total;
				break;
			case 'tickets':
				$start = 20; $end = 50; $total = (int) ( $totals['tickets'] ?? 0 ); break;
			case 'replies':
				$start = 50; $end = 75; $total = (int) ( $totals['replies'] ?? 0 ); break;
			case 'attachments':
				$start = 75; $end = 95; $total = (int) ( $totals['attachments'] ?? 0 ); break;
			case 'verify': return 99;
			case 'finished': return 100;
			default: return 0;
		}

		if ( $total <= 0 ) return $end;
		$fraction = min( 1, max( 0, $processed / $total ) );
		return (int) round( $start + ( ( $end - $start ) * $fraction ) );
	}

}

AS_Ticket_Migrator::instance();

register_activation_hook( __FILE__, array( 'AS_Ticket_Migrator', 'activate' ) );

/*
 * Register the download endpoint early enough for admin-post.php.
 */
add_action(
	'admin_post_astm_download',
	array( AS_Ticket_Migrator::instance(), 'download_zip' )
);
