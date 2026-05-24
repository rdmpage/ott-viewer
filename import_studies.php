<?php

ini_set('memory_limit', '512M');

$phylesystem = '/Users/rpage/Development/phylesystem-1/study';
$db_path     = __DIR__ . '/ott.db';

$db = new SQLite3($db_path);
$db->exec('PRAGMA journal_mode=WAL');

$stmt = $db->prepare('INSERT OR REPLACE INTO studies
    (study_id, publication_ref, doi, year, focal_clade_name, curator_names)
    VALUES (:study_id, :publication_ref, :doi, :year, :focal_clade_name, :curator_names)');

$count = 0;

$dirs = scandir($phylesystem);
foreach ($dirs as $dir_name) {
    if (!preg_match('/^(ot|pg)_\d+$/', $dir_name)) continue;

    $study_dirs = scandir($phylesystem . '/' . $dir_name);
    foreach ($study_dirs as $study_subdir) {
        if (!preg_match('/^(ot|pg)_\d+$/', $study_subdir)) continue;

        $json_filename = $phylesystem . '/' . $dir_name . '/' . $study_subdir . '/' . $study_subdir . '.json';
        if (!file_exists($json_filename)) continue;

        $json = file_get_contents($json_filename);
        $data = json_decode($json, true);
        if (!$data) continue;

        $nexml = isset($data['nexml']) ? $data['nexml'] : $data;

        $study_id = isset($nexml['^ot:studyId']) ? $nexml['^ot:studyId'] : $study_subdir;

        $publication_ref = isset($nexml['^ot:studyPublicationReference'])
            ? $nexml['^ot:studyPublicationReference'] : null;

        $doi = null;
        if (isset($nexml['^ot:studyPublication'])) {
            $pub = $nexml['^ot:studyPublication'];
            if (is_array($pub) && isset($pub['@href'])) {
                $doi = $pub['@href'];
            } elseif (is_string($pub)) {
                $doi = $pub;
            }
        }
        // Normalise DOI: strip URL prefix to get bare DOI
        if ($doi) {
            $doi = preg_replace('#^https?://(dx\.)?doi\.org/#', '', $doi);
        }

        $year = isset($nexml['^ot:studyYear']) ? (int)$nexml['^ot:studyYear'] : null;

        $focal_clade_name = isset($nexml['^ot:focalCladeOTTTaxonName'])
            ? $nexml['^ot:focalCladeOTTTaxonName'] : null;

        $curator_names = null;
        if (isset($nexml['^ot:curatorName'])) {
            $c = $nexml['^ot:curatorName'];
            $curator_names = is_array($c) ? json_encode($c) : json_encode([$c]);
        }

        $stmt->bindValue(':study_id',         $study_id,         SQLITE3_TEXT);
        $stmt->bindValue(':publication_ref',   $publication_ref,  SQLITE3_TEXT);
        $stmt->bindValue(':doi',              $doi,              SQLITE3_TEXT);
        $stmt->bindValue(':year',             $year,             SQLITE3_INTEGER);
        $stmt->bindValue(':focal_clade_name', $focal_clade_name, SQLITE3_TEXT);
        $stmt->bindValue(':curator_names',    $curator_names,    SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();

        $count++;
        if ($count % 500 === 0) {
            echo "$count studies imported...\n";
        }
    }
}

echo "Done. $count studies imported into $db_path\n";

$db->close();
