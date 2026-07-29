# Enroute Import Plugin

One-time migration tool to import content from the old Django/MariaDB CMS into WordPress.

**Deactivate and delete this plugin once the migration is complete.**

## Setup

1. Import the old CMS database dump into a local/server MariaDB instance
2. Create a read-only MySQL user for that database
3. Activate this plugin in WordPress
4. Go to **Enroute Import → Settings** and enter the DB credentials
5. Click **Test Connection** to verify

## Import order

Run imports in this order (offers reference stations, so stations must exist first):

1. Stations
2. Offers
3. Resources
4. Guides

## Re-running imports

Each import checks for an existing `_old_cms_id` meta value before inserting.  
Already-imported items are skipped — safe to re-run.

## TODO (fill in after reviewing old DB schema)

- [ ] Map old table/column names in each `includes/import-*.php` file
- [ ] Add taxonomy term mapping (subjects, target groups, offer types)
- [ ] Add image/file sideloading for offers (featured image), guides (photo), resources (file)
- [ ] Add language field mapping
