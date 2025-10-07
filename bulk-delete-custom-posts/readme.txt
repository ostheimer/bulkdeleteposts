=== Bulk Delete Custom Posts ===
Contributors: ostheimer
Tags: bulk delete, custom post types, taxonomy, admin tools
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Bulk Delete Custom Posts hilft Administratorinnen und Administratoren dabei, Beiträge eines frei wählbaren (auch benutzerdefinierten) Beitragstyps anhand verknüpfter Taxonomie-Begriffe zuverlässig in großen Mengen zu löschen. Der Ablauf wurde speziell für ressourcenschwache Shared-Hosting-Umgebungen optimiert und protokolliert jeden Löschvorgang in einem eigenen Custom Post Type.

= Hauptfunktionen =
* Auswahl beliebiger Post Types inklusive zugehöriger öffentlicher Taxonomien.
* Optionaler Filter nach Terminamen oder Slugs, um Treffer einzugrenzen.
* Trockendurchlauf (Dry Run) mit Ergebnisliste und Warnhinweisen vor dem endgültigen Löschen.
* Batch-Löschung mit konfigurierbarer Anzahl von Beiträgen je Durchlauf und optionalen Pausen zwischen den Batches.
* Automatisches Entfernen leerer, gefilterter Taxonomie-Begriffe nach Abschluss der Löschung.
* Umfangreiche Protokollierung inklusive Cron-gestützter Aufräumoptionen für alte Logeinträge.

= Für Shared Hosting optimiert =
* AJAX-basierte Prozesse verhindern Timeouts durch lange Seitenladezeiten.
* Auswahl konfigurierbarer Batch-Größen minimiert Datenbank- und Speicherlast.
* Fortschrittsanzeige und Fehlermeldungen über das Admin-Interface erleichtern die Überwachung.

= Hooks und Erweiterbarkeit =
Entwickelnde können das Verhalten über Filter und Actions (z. B. `bdcp_allowed_post_types`, `bdcp_pre_get_posts_args`, `bdcp_after_batch_delete`) anpassen und eigene Logik vor, während oder nach dem Löschprozess einhängen.

== Installation ==
1. ZIP-Datei herunterladen oder Repository klonen.
2. Den entpackten Ordner `bulk-delete-custom-posts` nach `/wp-content/plugins/` hochladen.
3. Im WordPress-Backend unter **Plugins** das Plugin **Bulk Delete Custom Posts** aktivieren.

== Verwendung ==
1. Im Dashboard zu **Werkzeuge → Bulk Delete Custom Posts** wechseln.
2. Beitragstyp und Taxonomie wählen.
3. (Optional) Suchbegriff für Terminame oder -slug eingeben.
4. (Optional) Batch-Größe und Pausen konfigurieren sowie das Löschen leerer Begriffe aktivieren.
5. Über **Find Posts (Dry Run Preview)** Treffer prüfen.
6. Mit deaktiviertem Dry-Run-Schalter **Delete Found Posts** starten.

== Screenshots ==
1. Admin-Ansicht mit Auswahl von Beitragstyp, Taxonomie und Filtern.
2. Fortschrittsanzeige während der Batch-Löschung.
3. Deletion-Log mit Übersicht über vergangene Aktionen.

== Frequently Asked Questions ==
= Kann ich nur Standard-Post-Types löschen? =
Nein. Alle registrierten, über das Backend verwaltbaren Post Types können ausgewählt werden. Entwicklerinnen und Entwickler können die Auswahl zusätzlich über den Filter `bdcp_allowed_post_types` einschränken oder erweitern.

= Werden automatisch Backups angelegt? =
Nein. Bitte unbedingt vor jeder Massenlöschung ein vollständiges Backup anlegen.

== Changelog ==
= 0.1.1 =
* Erste Veröffentlichung im Repository.

== Upgrade Notice ==
= 0.1.1 =
Erstveröffentlichung – bitte vor produktivem Einsatz in einer Staging-Umgebung testen.
