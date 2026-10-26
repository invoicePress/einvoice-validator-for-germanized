=== eInvoice Validator for Germanized ===
Contributors: naranili
Tags: e-rechnung, xrechnung, zugferd, peppol, germanized
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prüft jede E-Rechnung, die Germanized Pro erzeugt, im eigenen Shop gegen die amtlichen Regeln und hält eine fehlerhafte Rechnung zurück, bevor sie hinausgeht.

== Description ==

eInvoice Validator for Germanized ist ein unabhängiges Plugin. Es stammt nicht von vendidero, dem Hersteller von Germanized. Es prüft die E-Rechnungen, die Germanized Pro zu Ihren WooCommerce-Bestellungen schreibt ( ZUGFeRD, XRechnung, Peppol ), gegen EN 16931 und ihre nationalen Regeln, mit der Prüfmaschine VAMPIRE PHP auf Ihrem eigenen Server.

Germanized Pro erzeugt E-Rechnungen, prüft sie aber nicht. Eine fehlerhafte E-Rechnung fällt meist erst auf, wenn der Kunde sie zurückweist, und dann hat sie schon eine Nummer und braucht eine Stornorechnung. Dieses Plugin prüft die Rechnung, solange sie noch ein Entwurf ist.

= So arbeitet es =

1. Germanized Pro legt die Rechnung einer Bestellung wie gewohnt als Entwurf an.
2. Das Plugin lässt Germanized Pro die E-Rechnungen des Entwurfs im Speicher schreiben und prüft sie.
3. Sind sie gültig, schreibt das Plugin die Rechnung fest, und Germanized Pro vergibt die Nummer und verschickt sie wie gewohnt.
4. Haben sie Fehler, bleibt die Rechnung ein Entwurf. Sie bekommen eine Notiz an der Bestellung und eine Mail, die jeden Fehler in Klartext nennt, mit dem Feld und dem, was zu tun ist.
5. Nachdem Sie die Bestellung berichtigt haben, klicken Sie „Erneut prüfen“. Sie können die Rechnung auch ohne Prüfung festschreiben.

Nach dem Festschreiben prüft das Plugin die fertigen Dateien noch einmal. Ein Fehler, der erst dort auftritt, hält den Versand der Rechnung an den Kunden an.

Die Bestellung selbst wird nie angefasst. Kasse, Zahlung, Bestellstatus und Downloads laufen wie immer; nur die Rechnung wartet.

= Unternehmen und Privatkunden =

Sie wählen je Kundengruppe, was mit einer fehlerhaften Rechnung geschieht: zurückhalten, trotzdem festschreiben und die Fehler nur melden, oder nicht prüfen. Voreingestellt werden Rechnungen an Unternehmen zurückgehalten und Rechnungen an Privatkunden nur gemeldet. Eine Bestellung gilt als Bestellung eines Unternehmens, wenn sie einen Firmennamen, eine USt-IdNr. oder eine Käuferreferenz trägt.

= Felder in der Kasse =

Auf Wunsch fragt die Kasse nach dem, was eine E-Rechnung an ein Unternehmen braucht: ob der Käufer ein öffentlicher Auftraggeber ist, die eigene Bestellnummer des Käufers und seine Peppol-ID. Die Felder erscheinen nur, wenn ein Firmenname eingetragen ist; das Firmenfeld der Kasse muss deshalb eingeschaltet sein. Sie funktionieren in der Block-Kasse und in der klassischen Kasse. Sie können sie auch an der Bestellung eintragen oder berichtigen.

Ein öffentlicher Auftraggeber setzt ein Häkchen und muss dann seine Leitweg-ID eingeben. Die Kasse prüft die Prüfziffern der Leitweg-ID und weist eine falsche zurück. Für jeden anderen Käufer schreibt das Plugin die Bestellnummer in die Käuferreferenz, wo das Format eine verlangt: immer bei der XRechnung und bei Peppol, wenn Verkäufer und Käufer beide in Deutschland sitzen.

Das Häkchen, die Leitweg-ID und die Peppol-ID bleiben auch beim Kunden gespeichert. Ein wiederkehrender Kunde findet sie ausgefüllt vor, und sie lassen sich unter Mein Konto -> Adressen und im Benutzerprofil im Admin ändern.

Das Feld für die Peppol-ID erscheint nur, wenn Germanized Pro Peppol-Dateien erzeugt. Weder Germanized Pro noch dieses Plugin versendet Rechnungen über das Peppol-Netz; die Peppol-ID braucht ein Shop, der seine Peppol-Dateien selbst an einen Zugangspunkt weitergibt. Ohne Peppol-ID vom Kunden bildet das Plugin die Peppol-Adresse aus der USt-IdNr. des Käufers, mit dem Adressschema des Landes der USt-IdNr.

= Prüfberichte =

Jede Prüfung hinterlässt an der Bestellung einen Bericht im Berichtsformat des KoSIT-Validators. Sie können ihn dort herunterladen; an den Kunden geht er nie. Der Ordner der Berichte ist für direkten Zugriff gesperrt.

= Selbsttest =

Die Seite WooCommerce -> E-Rechnungsprüfung -> Selbsttest sucht Einstellungen, an denen jede E-Rechnung scheitern würde: USt-IdNr. und Anschrift des Verkäufers, Kontaktangaben, Bankverbindung, die automatische Erzeugung von E-Rechnungen in Germanized Pro, das Firmenfeld der Kasse und den Arbeitsspeicher.

= Bekannter Fehler von Germanized Pro =

Germanized Pro 4.3.10 kennzeichnet die PDF, die es in Peppol- und XRechnung-UBL-Dateien einbettet, mit einem falschen Typ. Das Plugin zeigt diesen Fehler an jeder Rechnung und meldet ihn einmal per Mail, hält aber deswegen keine Rechnung zurück, weil Sie ihn im Shop nicht beheben können. ZUGFeRD-Dateien sind nicht betroffen.

== External services ==

**Dieses Plugin ruft keinen fremden Dienst auf.** Die Prüfung läuft auf Ihrem Server. Das Plugin schickt nichts an irgendeinen Server, auch nicht an seinen Hersteller.

== Installation ==

1. Installieren und aktivieren Sie WooCommerce und Germanized Pro.
2. Schalten Sie in Germanized Pro die automatische Erzeugung von E-Rechnungen und das automatische Festschreiben von Rechnungen ein.
3. Installieren und aktivieren Sie dieses Plugin.
4. Öffnen Sie WooCommerce -> E-Rechnungsprüfung -> Selbsttest und beheben Sie, was er meldet.
5. Wählen Sie unter WooCommerce -> E-Rechnungsprüfung -> Einstellungen, was bei Unternehmen und bei Privatkunden geschieht.

PHP 8.2 ist Voraussetzung. Eine Prüfung braucht etwa 25 MB zusätzlich zu dem Arbeitsspeicher, den Ihr Shop schon belegt; empfohlen sind 256 MB.

== Frequently Asked Questions ==

= Verändert das Plugin Germanized Pro? =

Nein. Solange es aktiv ist, beantwortet es eine Frage von Germanized Pro anders: ob eine Rechnung sofort festgeschrieben wird. Die gespeicherte Einstellung bleibt, wie Sie sie gesetzt haben. Deaktivieren Sie das Plugin, arbeitet Germanized Pro wie vorher.

= Was geschieht, wenn die Prüfung selbst scheitert? =

Dann wird die Rechnung festgeschrieben, wie Germanized Pro es getan hätte, und die Notiz an der Bestellung sagt, dass nicht geprüft wurde. Ein technisches Problem der Prüfung hält nie eine Rechnung zurück.

= Geht es ohne automatisches Festschreiben? =

Ja, mit weniger Wirkung. Dann prüft das Plugin die fertigen Dateien, nachdem Sie eine Rechnung von Hand festgeschrieben haben, und hält in der Einstellung „zurückhalten“ nur den Versand an.

= Welche Formate werden geprüft? =

Jede Datei, die Germanized Pro automatisch erzeugt: ZUGFeRD in seinen Profilen, XRechnung und Peppol BIS Billing 3. Die Prüfmaschine wählt die Regeln aus der Datei selbst.

== Changelog ==

= 1.0.1 =
* Wartungsfassung ohne sichtbare Änderung.

= 1.0.0 =
* Eine Prüfregel, die sich für eine Rechnung nicht auswerten lässt, bricht die Prüfung nicht mehr ab; der Bericht nennt sie als nicht auswertbar.
* Erste Fassung: Prüfung der E-Rechnungen von Germanized Pro vor dem Festschreiben, zurückhalten oder melden je Kundengruppe, freiwillige Felder in der Kasse für öffentliche Auftraggeber mit Leitweg-ID, Bestellnummer und Peppol-ID, beim Kunden gespeichert, Prüfberichte an der Bestellung, Übersicht der zurückgehaltenen Rechnungen und Selbsttest.
