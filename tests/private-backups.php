<?php
declare(strict_types=1);

define('ABSPATH',__DIR__.'/../');
$vault=sys_get_temp_dir().'/staycore-vault-test-'.bin2hex(random_bytes(6));
define('STAYCORE_PRIVATE_BACKUP_DIR',$vault);
function wp_json_encode($value) { return json_encode($value); }
require_once __DIR__.'/../includes/class-staycore-private-backups.php';

function failtest(bool $passed,string $name): void {
    if(!$passed){fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}
    echo 'PASS: '.$name.PHP_EOL;
}
$payload=json_encode(['schema_version'=>1,'reservation'=>['id'=>12,'total'=>'1200.00'],'payments'=>[['amount'=>'600.00']]]);
$checksum=hash('sha256',$payload);
failtest(StayCore_Private_Backups::save(12,'after_reservation_update',$payload,$checksum),'Encrypted backup file saved');
$files=StayCore_Private_Backups::files(12);
failtest(count($files)===1,'Private archive listed by booking ID only');
$on_disk=file_get_contents($vault.'/'.$files[0]);
failtest(!str_contains($on_disk,'1200.00')&&!str_contains($on_disk,'600.00'),'Private encrypted file does not disclose booking amounts');
failtest(is_file($vault.'/vault.key') && strlen(file_get_contents($vault.'/vault.key'))===32,'32-byte key stored outside public WordPress directory');
failtest(StayCore_Private_Backups::attests(12,$checksum),'Encrypted vault attests to a matching database revision');
$decoded=StayCore_Private_Backups::read(12,$files[0]);
failtest($decoded['reservation']['id']===12&&$decoded['reservation']['total']==='1200.00','Private archive decrypts to original booking');
failtest(StayCore_Private_Backups::read(13,$files[0])===null,'Archive cannot be read as another booking');
failtest(StayCore_Private_Backups::read(12,'../../vault.key')===null,'Path traversal rejected');
failtest(!StayCore_Private_Backups::save(12,'after_reservation_update',$payload,str_repeat('0',64)),'Wrong checksum prevents storing backup');
$parts=json_decode($on_disk,true);
$parts['ciphertext']=base64_encode('tampered'.$parts['ciphertext']);
file_put_contents($vault.'/'.$files[0],json_encode($parts));
failtest(StayCore_Private_Backups::read(12,$files[0])===null,'Corrupted ciphertext fails authenticated decryption');
failtest(!StayCore_Private_Backups::attests(12,$checksum),'Tampered archive cannot authorize restoration');
foreach(glob($vault.'/*')?:[] as $file) unlink($file);
rmdir($vault);
