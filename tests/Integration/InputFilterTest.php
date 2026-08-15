<?php

declare(strict_types=1);

use LexNova\InputFilter\DocumentInputFilter;
use LexNova\InputFilter\EntityInputFilter;
use LexNova\InputFilter\Fail2BanSettingInputFilter;
use LexNova\InputFilter\LoginInputFilter;
use LexNova\InputFilter\PasskeyCredentialInputFilter;
use LexNova\InputFilter\PasskeyLabelInputFilter;
use LexNova\InputFilter\PasskeyTargetInputFilter;
use LexNova\InputFilter\TotpEnrollmentInputFilter;
use LexNova\InputFilter\TotpVerificationInputFilter;
use LexNova\InputFilter\UserCreateInputFilter;
use LexNova\InputFilter\UserUpdateInputFilter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$entity = new EntityInputFilter();
$entity->setData([
    'name' => '  Beispiel GmbH  ',
    'contact_data' => " Straße 1\r\n12345 Ort ",
]);
if (!$entity->isValid() || $entity->getValues() !== [
    'name' => 'Beispiel GmbH',
    'contact_data' => "Straße 1\n12345 Ort",
]) {
    throw new RuntimeException('Entity input filtering changed unexpectedly.');
}

$document = new DocumentInputFilter();
$document->setData([
    'entity_id' => '42',
    'type' => 'imprint',
    'language' => 'de-DE',
    'content' => " Inhalt\r\nZeile 2 ",
    'version' => ' 1.0 ',
]);
if (!$document->isValid() || $document->getValues()['content'] !== "Inhalt\nZeile 2") {
    throw new RuntimeException('Document input filtering changed unexpectedly.');
}

$document->setData([
    'entity_id' => ['42'],
    'type' => 'script',
    'language' => '../de',
    'content' => '',
    'version' => str_repeat('x', 51),
]);
if ($document->isValid() || count($document->getMessages()) !== 5) {
    throw new RuntimeException('Invalid or structured form values were accepted.');
}

$login = new LoginInputFilter();
$login->setData(['username' => '  admin@example.test ', 'password' => 'secret']);
if (!$login->isValid() || $login->getValues()['username'] !== 'admin@example.test') {
    throw new RuntimeException('Login input was not normalized.');
}
$login->setData(['username' => ['admin'], 'password' => str_repeat('x', 257)]);
if ($login->isValid()) {
    throw new RuntimeException('Structured or oversized login input was accepted.');
}

$userCreate = new UserCreateInputFilter();
$userCreate->setData([
    'username' => 'fido-user',
    'role' => 'admin',
    'authentication' => 'passkey',
    'password' => '',
    'password_confirm' => '',
]);
if (!$userCreate->isValid()) {
    throw new RuntimeException('A valid Passkey-only user was rejected.');
}
$userCreate->setData([
    'username' => 'password-user',
    'role' => 'admin',
    'authentication' => 'password',
    'password' => '',
    'password_confirm' => '',
]);
if ($userCreate->isValid()) {
    throw new RuntimeException('A password account without a password was accepted.');
}

$userUpdate = new UserUpdateInputFilter();
$userUpdate->setData(['role' => 'admin', 'new_password' => '', 'password_login_enabled' => '0']);
if (!$userUpdate->isValid()) {
    throw new RuntimeException('A valid Passkey-only account update was rejected.');
}

$totp = new TotpEnrollmentInputFilter();
$totp->setData(['code' => '123456', 'label' => ' YubiKey ']);
if (!$totp->isValid() || $totp->getValues()['label'] !== 'YubiKey') {
    throw new RuntimeException('TOTP input was not validated and normalized.');
}

$totpVerification = new TotpVerificationInputFilter();
$totpVerification->setData(['code' => '12345x']);
if ($totpVerification->isValid()) {
    throw new RuntimeException('An invalid TOTP code was accepted.');
}

$passkey = new PasskeyCredentialInputFilter(true);
$passkey->setData([
    'credential' => '{"id":"test"}',
    'label' => ' Security Key ',
    'user_id' => '1',
    'attachment' => 'cross-platform',
]);
if (!$passkey->isValid() || $passkey->getValues()['label'] !== 'Security Key') {
    throw new RuntimeException('Passkey input was not validated and normalized.');
}
$passkey->setData(['credential' => '{"id":"test"}', 'label' => 'Key', 'attachment' => 'virtual']);
if ($passkey->isValid()) {
    throw new RuntimeException('An invented authenticator attachment was accepted.');
}

$passkeyTarget = new PasskeyTargetInputFilter();
$passkeyTarget->setData(['user_id' => '23']);
if (!$passkeyTarget->isValid() || $passkeyTarget->getValues()['user_id'] !== '23') {
    throw new RuntimeException('A valid Passkey target was rejected.');
}

$passkeyLabel = new PasskeyLabelInputFilter();
$passkeyLabel->setData(['label' => '  Hauptschlüssel  ']);
if (!$passkeyLabel->isValid() || $passkeyLabel->getValues()['label'] !== 'Hauptschlüssel') {
    throw new RuntimeException('A valid Passkey name was not normalized.');
}

$fail2ban = new Fail2BanSettingInputFilter();
$fail2ban->setData(['mode' => 'anything']);
if ($fail2ban->isValid()) {
    throw new RuntimeException('An invalid Fail2ban mode was accepted.');
}

echo "Laminas input filter integration test: OK\n";
