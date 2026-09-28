<?php
declare(strict_types=1);

$__missionReminderPayload = is_array($__missionLoginReminder ?? null)
    ? $__missionLoginReminder
    : null;
if ($__missionReminderPayload !== null):
    $__missionReminderJson = json_encode(
        $__missionReminderPayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
?>
<script>
window.RISFM_LOGIN_MISSION_REMINDER = <?= is_string($__missionReminderJson) ? $__missionReminderJson : 'null' ?>;
</script>
<?php endif; ?>
