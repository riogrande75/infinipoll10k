<?PHP
$debug = 0;
$infile = "/tmp/inv1/STORAGESTAT.txt"; //Batterie-Logdatei von Infinipoll10k
$zeilen2 = file($infile);

$lines = readline("Startzeit (Format 20210315-07:17.22):");
if($lines)
        {
                $start = mktime(substr($lines, 9, 2), substr($lines, 12, 2), substr($lines, 15, 2), substr($lines, 4, 2), substr($lines, 6, 2), substr($lines, 0, 4));
        } else {
                echo "Keine Startzeit eingegeben, nehme 1. Eintrag aus File\n";
                $parts = explode(" ", trim($zeilen2[0]));
                $start = $parts[0];
        }
echo "START: $start\n";

$linee = readline("Endzeit (Format 20210316-12:00.48):");
if($linee)
        {
        $ende = mktime(substr($linee, 9, 2), substr($linee, 12, 2), substr($linee, 15, 2), substr($linee, 4, 2), substr($linee, 6, 2), substr($linee, 0, 4));
        } else {
                echo "Keine Endzeit eingegeben, nehme letzten Eintrag aus File\n";
                $endzeile = sizeof($zeilen2);
                $parts = explode(" ", trim($zeilen2[$endzeile -1 ]));
                $ende = $parts[0];
        }
echo "ENDE: $ende\n";

$ladenWh = 0;
$entlWh = 0;

$ts_vorh = 0;
echo "Bearbeitet werden:".sizeof($zeilen2)." Zeilen\n";

for($i = 0; $i < sizeof($zeilen2); $i++)
{
        $parts = explode(" ", trim($zeilen2[$i]));
        $time = $parts[0];
        $leist = (float) $parts[1];
        $timest = mktime(substr($time, 9, 2), substr($time, 12, 2), substr($time, 15, 2), substr($time, 4, 2), substr($time, 6, 2), substr($time, 0, 4));

        if($timest==$start){
                echo "Startzeitpunkt $parts[0] Batterie zu $parts[2] voll.\n";
                $ladenWh =0;
                $entlWh =0;
        }
        if($ts_vorh > 0)
        {
                $ts_diff = $timest - $ts_vorh;

                $leist_in_Ws = $leist * $ts_diff;
                $leist_in_Wh = $leist_in_Ws /3600;

                if($leist_in_Wh > 0) $ladenWh += $leist_in_Wh;
                else $entlWh += $leist_in_Wh;

                if($debug) echo "DEBUG: $timest, $time, $leist, ". date("Y-m-d H:i:s", $timest).", $leist_in_Ws, $leist_in_Wh, $ts_diff\r\n";
        }

        $ts_vorh = $timest;
        if($timest==$ende){
                echo "Endzeitpunkt = $parts[0] Batterie zu $parts[2] voll.\n";
                $zeilen2=[];
                }
}
$ladenKWh = $ladenWh/1000;
$entlKWh =  ($entlWh/1000)*-1;
$effizienz = $entlWh/$ladenWh;
$effpro = (100 * $effizienz) *-1;
echo "Laden: $ladenKWh kWh, entl: $entlKWh kWh, also $effpro % Effizienz\r\n";
?>
