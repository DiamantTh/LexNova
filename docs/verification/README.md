# Verifikationsberichte

Jeder Bericht nennt im Abschnitt **Bezug** mindestens:

- den geprüften Source- beziehungsweise Funktionscommit,
- dessen Commitzeit mit ausgeschriebener Zeitzone und UTC-Offset,
- den ursprünglichen Commit, der den Bericht angelegt hat,
- die ursprüngliche Erstellungszeit mit Zeitzone und UTC-Offset,
- den geprüften Branch und die relevante Laufzeitmatrix.

Source- und Berichtscommit sind bewusst getrennt. Der Bericht wird nach der
Prüfung angelegt und darf deshalb nicht so aussehen, als sei er Bestandteil des
bereits geprüften Source-Commits gewesen. Spätere redaktionelle Ergänzungen sind
zusätzlich über die Git-Historie der jeweiligen Datei nachvollziehbar.

Alle Zeitangaben werden aus Git übernommen. Die Angabe enthält neben der
lokalen Zeitzone immer den numerischen UTC-Offset, damit Sommer- und Winterzeit
eindeutig bleiben.
