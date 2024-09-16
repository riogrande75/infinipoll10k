#!/usr/bin/php
<?php
// Activates battery emergency charging from grid
$moxa_ip = "192.168.x.y"; //IP of USR-TCP304
$moxa_port = xxxxx; //Infinisolar 10K
$moxa_timeout = 10;
$debug = 0;

$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
fwrite($fp,"^P005HECS".chr(0x0d));
$antw = parse_antw();
if ($debug) {
        echo "HECS:\n";
        print_r($antw);
        }
if ($antw[2]=="1"){
        $acladen = "ein";
        } else
        {
        $acladen = "aus";
        }
echo "HECS sagt, AC-Laden ist $acladen\n";

// BATS command
fwrite($fp, "^P005BATS".chr(0x0d));
$antw = parse_antw();
if ( is_array($antw) and count($antw) >= 10 ) {
if ($debug) print_r($antw);
$bats_chg = ((int)substr($antw[0],5,4)/10);
$bats_acchg = $antw[16]/10; // Max. AC charging current
}
echo "BATS sagt, Ladestrom ist aktuell ".$bats_chg."A und Max.AC-Ladestrom ".$bats_acchg."A\n";
sleep(1);

echo "**** Jetzt schalten wir NOTLADEN AUS! ****\n";
//$check = cal_crc_half("^S005EDB1");
fwrite($fp, "^S005EDB0".chr(0x0d));
$antw =  parse_antw();
echo "ANTWORT von S005EDB: ";
if ($debug) print_r($antw);
if($antw[0] != "^1" ) {
        echo "FEHLER!\n";
        } else {
        echo "OK!\n";
        }
if ($debug) print_r($antw);
fwrite($fp,"^P005HECS".chr(0x0d));
$antw = parse_antw();
if ($debug) {
        echo "**** HECS:\n";
        print_r($antw);
        }
if ($antw[2]=="1"){
        $acladen = "ein";
        } else
        {
        $acladen = "aus";
        }
echo "**** HECS sagt, AC-Laden ist jetzt $acladen\n";
fwrite($fp, "^S011MUCHGC0010".chr(0x0d));
$antw =  parse_antw();
echo "ANTWORT von MUCHGC: ";
if ($debug) print_r($antw);
if($antw[0] != "^1" ) {
        echo "FEHLER!\n";
        } else {
        echo "OK!\n";
        }
fwrite($fp, "^S010MCHGC0010".chr(0x0d));
$antw =  parse_antw();
echo "ANTWORT von MCHGC: ";
if ($debug) print_r($antw);
if($antw[0] != "^1" ) {
        echo "FEHLER!\n";
        } else {
        echo "OK!\n";
        }
// BATS command
fwrite($fp, "^P005BATS".chr(0x0d));
$antw = parse_antw();
if ( is_array($antw) and count($antw) >= 10 ) {
if ($debug) print_r($antw);
$bats_chg = ((int)substr($antw[0],5,4)/10);
$bats_acchg = $antw[16]/10; // Max. AC charging current
}
echo "BATS sagt, Ladestrom ist jetzt aktuell ".$bats_chg."A und Max.AC-Ladestrom ".$bats_acchg."A\n";
fclose($fp);

//neede functions
function hex2str($hex) {
    $str = '';
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
}
function cal_crc_half($pin)
        {
        $sum = 0;
        for($i = 0; $i < strlen($pin); $i++)
                {
                $sum += ord($pin[$i]);
                }
        $sum = $sum % 256;
        if(strlen($sum)==2) $sum="0".$sum;
        if(strlen($sum)==1) $sum="00".$sum;
        return $sum;
}
function parse_antw()
        {
        global $fp, $debug;
        $byte="";
        $s="";
        if($fp){
                while( $fp && ($s != chr(13)) )
                        {
                        $s=fgetc($fp);
                        If($s === false) return $byte;
                        $byte=$byte.$s;
                        }
                }
                else exit(7);
        $byte_ok = substr($byte,0,-3);
        $antw_werte = explode(",",$byte_ok);
        return($antw_werte);
}
?>
