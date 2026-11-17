# bulkdeleteposts

WordPress-Plugin „Bulk Delete Custom Posts“ (Version 0.1.1, GPL v2+): löscht Beiträge eines wählbaren Post Types anhand von Taxonomie-Begriffen, in Batches per AJAX, mit Dry-Run-Vorschau und Protokoll im Custom Post Type `bdcp_log`. Die Feature-Beschreibung steht in `bulk-delete-custom-posts/README.md`.

- Das Plugin liegt im Unterordner `bulk-delete-custom-posts/` (Hauptdatei `bulk-delete-custom-posts.php`, dazu `admin-script.js`, `readme.txt`); dieser Ordner wird nach `wp-content/plugins/` kopiert. Es gibt kein Build, keine Tests, keinen Workflow und keinen Deploy: Veröffentlicht wird per ZIP oder Klon laut README.
- Aufruf unter Werkzeuge → Bulk Delete Custom Posts, nur mit der Fähigkeit `manage_options`.
- Falle: Die Batch-Löschung ruft `wp_delete_post(..., true)` auf (`$force_delete = true`). Gelöschte Beiträge umgehen den Papierkorb und sind weg; ein Test gegen eine Seite mit echten Inhalten löscht diese endgültig. Der Dry Run ist die einzige Vorschau.
- Optionen, Transients und Hooks tragen das Präfix `bdcp_`.
