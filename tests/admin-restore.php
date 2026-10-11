<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/../');
$GLOBALS['user_id']=0;
$GLOBALS['admin_allowed']=false;
function get_current_user_id(): int { return $GLOBALS['user_id']; }
function current_user_can(string $cap): bool { return $cap==='manage_options' && $GLOBALS['admin_allowed']; }
class WP_Error {
    private string $code;
    public function __construct(string $code,string $message='',array $details=[]) {$this->code=$code;}
    public function get_error_code(): string { return $this->code; }
}
require_once __DIR__.'/../includes/class-staycore-recovery-admin.php';
function check(bool $yes,string $message): void {
    if(!$yes){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}
    echo 'PASS: '.$message.PHP_EOL;
}
check(!StayCore_Recovery_Admin::allowed(),'Logged-out users cannot recover bookings');
$GLOBALS['user_id']=7;
check(!StayCore_Recovery_Admin::allowed(),'Regular PMS staff cannot restore a booking');
$r=StayCore_Recovery_Admin::apply(12,44,'fingerprint','Reason for data correction');
check($r instanceof WP_Error && $r->get_error_code()==='forbidden','Non-admin restore is rejected before accessing database');
$GLOBALS['admin_allowed']=true;
check(StayCore_Recovery_Admin::allowed(),'WordPress administrator can access recovery UI');
$r=StayCore_Recovery_Admin::apply(12,44,'fingerprint','Reason for data correction');
check($r instanceof WP_Error && $r->get_error_code()==='recovery_disabled','Production restore defaults to disabled unless explicitly enabled');

$src=file_get_contents(__DIR__.'/../includes/class-staycore-recovery-admin.php');
check(str_contains($src,"check_admin_referer('staycore_recovery_preview')"),'Preview requires WordPress CSRF nonce');
check(str_contains($src,"check_admin_referer('staycore_recovery_apply')"),'Apply requires WordPress CSRF nonce');
check(str_contains($src,'self::TICKET_TTL'),'Preview requires an expiring ticket');
check(str_contains($src,"'before_admin_restore'")&&str_contains($src,"'after_admin_restore'"),'Rollback and restored states are both protected');
check(str_contains($src,"'action'=>'admin_booking_restored'"),'Restore creates dedicated audit record');
check(str_contains($src,"'payment_policy'=>'unchanged'"),'Restore audit states payment ledger is preserved');
check(str_contains($src,"status='captured'"),'Restore checks already captured payments');
check(!str_contains($src,"\$wpdb->delete(\$t['payments']"),'Restore cannot delete recorded payments');
