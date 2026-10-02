# Awesome Support Ticket Migrator

**Package-based exporter/importer for Awesome Support tickets, replies, users, terms, metadata and related media.**

Works between **independent WordPress installations** without requiring direct database access, remote API credentials, or server-level access to the other site.

- **Version:** 1.0.0  
- **Author:** DevThrow  
- **License:** GPL-2.0-or-later  
- **Requires:** WordPress 5.8+, PHP 7.4+ (ZipArchive extension required)

---

## Features

- Export tickets + replies + related users + taxonomies/terms + post meta + media attachments into a single ZIP package
- Import the package on a completely separate WordPress site
- Three import modes:
  - **Skip** existing matching records
  - **Update** matching records
  - **Duplicate** (always create new records)
- **Dry-run** mode (validate without writing anything)
- Batch processing (prevents timeouts on large sites)
- Chunked ZIP upload (handles packages larger than PHP `upload_max_filesize` / `post_max_size`)
- Configurable metadata keys (extra keys to include / keys to exclude)
- Support for additional reply post types (useful with extensions)
- Automatic URL rewriting from source site → destination site
- ID remapping for users, posts, replies and attachments
- Secure temporary workspace (private directory + `.htaccess` deny)
- Zip-Slip protection on import
- Clear progress bars + live log

---

## Important Security Notes

- **No passwords are ever exported.**  
  Existing users on the destination are matched by email/username. New users are created with strong random passwords.
- No API keys, secrets, tokens, session data or database credentials are included in the package.
- Plugin settings, scheduled tasks, transients and unrelated site configuration are **not** migrated.
- The plugin must be installed and activated on **both** the source and destination sites.
- Only users with the `manage_options` capability can run the tool.

---

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- PHP `ZipArchive` extension enabled
- Awesome Support (or a compatible ticket system using the configured post types)
- Ability to write to the WordPress uploads directory

---

## Installation

1. Download the plugin ZIP or clone this repository.
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate **Awesome Support Ticket Migrator** on **both** the source and destination WordPress sites.
4. Go to **Tools → Awesome Support Migrator**.

---

## Usage

### 1. Export (run on the **source** site)

1. Go to **Tools → Awesome Support Migrator**.
2. Click **Start Export**.
3. Wait for the process to finish (progress bar + log).
4. Download the generated migration ZIP.

The package contains:

- `manifest.json` – package metadata & counts
- `tickets.jsonl`
- `replies.jsonl`
- `users.jsonl`
- `attachments.jsonl`
- `uploads/` – media files that could be packaged

### 2. Import (run on the **destination** site)

1. Go to **Tools → Awesome Support Migrator**.
2. Choose import mode:
   - **Skip existing matching records** (safest)
   - **Update matching records**
   - **Always create duplicates**
3. (Optional) Enable **Dry run** to test without writing data.
4. Select the ZIP package and click **Validate & Import**.
5. The plugin uploads the file in 8 MB chunks, validates the package, extracts it, and processes records in batches.

Matching is performed primarily via a hidden meta key (`_astm_source_id`) written during previous migrations, with a conservative title + date fallback.

---

## Advanced Configuration

On the migrator page you can edit:

| Setting                        | Purpose |
|--------------------------------|---------|
| **Extra metadata keys**        | Force inclusion of additional post-meta keys (one per line) |
| **Metadata keys to exclude**   | Keys that should never be migrated (defaults include `_edit_lock` and `_edit_last`) |
| **Additional reply post types**| Extra post types that should be treated as replies (default: `ticket_reply`) |

Click **Save configuration** after making changes.

These options exist because Awesome Support extensions often store custom fields and media references under different meta keys.

---

## What Is Migrated

- Tickets (default post type `ticket`)
- Replies (default `ticket_reply` + any configured extra types)
- Users related to tickets/replies (author, assignees, agents, customers, etc.)
- Taxonomies & terms attached to tickets/replies
- All non-excluded post meta
- Attachment files that live inside the WordPress uploads directory and are readable by PHP
- Attachment post records + their meta

## What Is **Not** Migrated

- User passwords / authentication data
- Plugin settings & options
- Scheduled jobs / cron events
- Transients
- API credentials / secrets
- Files outside the WordPress uploads directory
- Unrelated posts, pages, or other content

---

## Compatibility Notes

- Designed for Awesome Support 4+ (and compatible forks/extensions).
- Exact storage used by some paid extensions can vary — use the configuration fields for custom meta keys.
- Attachments are only packaged when the PHP process can read the original file from the uploads directory.
- Large sites are handled via batched AJAX requests (25 records per batch by default).

---

## Troubleshooting

| Issue | Possible cause / solution |
|-------|---------------------------|
| Export fails with “Could not create a private migration directory” | Check that the uploads directory is writable |
| “PHP ZipArchive is required” | Enable the ZipArchive extension in PHP |
| Import stuck or progress freezes | Check browser console + PHP error log; increase `max_execution_time` if needed |
| Attachments missing | Source files may be outside uploads or unreadable by the web server |
| Users not matched | Matching is by email first, then username. New users receive random passwords |

---

## Development / File Structure
