function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
  const normalized = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
  return Uint8Array.from(atob(normalized), (character) => character.charCodeAt(0));
}

function toBase64Url(value: ArrayBuffer): string {
  return btoa(String.fromCharCode(...new Uint8Array(value)))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

async function post(url: string, csrfToken: string, data: Record<string, string>): Promise<Record<string, unknown>> {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ __csrf: csrfToken, ...data }),
    credentials: 'same-origin',
  });
  const result: unknown = await response.json();
  if (typeof result !== 'object' || result === null || Array.isArray(result)) {
    throw new Error('Der Server hat eine ungültige Antwort geliefert.');
  }
  return result as Record<string, unknown>;
}

export function passkeysSupported(): boolean {
  return Boolean(window.PublicKeyCredential && navigator.credentials);
}

export async function loginWithPasskey(username: string, csrfToken: string, mode: 'primary' | 'mfa' = 'primary'): Promise<string> {
  const result = await post('/admin/passkeys/login/options', csrfToken, mode === 'mfa' ? { mode } : { username });
  if (typeof result.error === 'string') throw new Error(result.error);

  const options = result as unknown as PublicKeyCredentialRequestOptionsJSON;
  const publicKey: PublicKeyCredentialRequestOptions = {
    ...options,
    challenge: fromBase64Url(options.challenge),
    allowCredentials: options.allowCredentials?.map((credential) => ({ ...credential, id: fromBase64Url(credential.id) })),
  };
  const credential = await navigator.credentials.get({ publicKey });
  if (!(credential instanceof PublicKeyCredential) || !(credential.response instanceof AuthenticatorAssertionResponse)) {
    throw new Error('Der Browser hat keinen gültigen Passkey geliefert.');
  }

  const response = credential.response;
  const payload = assertionPayload(credential);
  const finish = await post('/admin/passkeys/login/finish', csrfToken, { credential: JSON.stringify(payload) });
  if (typeof finish.error === 'string') throw new Error(finish.error);
  if (typeof finish.redirect !== 'string') throw new Error('Die Anmeldung lieferte kein Weiterleitungsziel.');
  return finish.redirect;
}

function assertionPayload(credential: PublicKeyCredential): Record<string, unknown> {
  if (!(credential.response instanceof AuthenticatorAssertionResponse)) {
    throw new Error('Der Browser hat keinen gültigen FIDO2-Nachweis geliefert.');
  }
  const response = credential.response;
  return {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      authenticatorData: toBase64Url(response.authenticatorData),
      clientDataJSON: toBase64Url(response.clientDataJSON),
      signature: toBase64Url(response.signature),
      userHandle: response.userHandle ? toBase64Url(response.userHandle) : null,
    },
  };
}

export async function registerPasskey(userId: number, label: string, csrfToken: string, mode: 'any' | 'hardware' = 'any'): Promise<string> {
  const result = await post('/admin/passkeys/register/options', csrfToken, { user_id: String(userId), mode });
  if (typeof result.error === 'string') throw new Error(result.error);

  const options = result as unknown as PublicKeyCredentialCreationOptionsJSON;
  const publicKey: PublicKeyCredentialCreationOptions = {
    ...options,
    challenge: fromBase64Url(options.challenge),
    user: { ...options.user, id: fromBase64Url(options.user.id) },
    excludeCredentials: options.excludeCredentials?.map((credential) => ({ ...credential, id: fromBase64Url(credential.id) })),
  };
  const credential = await navigator.credentials.create({ publicKey });
  if (!(credential instanceof PublicKeyCredential) || !(credential.response instanceof AuthenticatorAttestationResponse)) {
    throw new Error('Der Browser hat keinen gültigen Passkey erstellt.');
  }

  const response = credential.response;
  const payload = registrationPayload(credential, response);
  const finish = await post('/admin/passkeys/register/finish', csrfToken, {
    label,
    credential: JSON.stringify(payload),
    attachment: credential.authenticatorAttachment ?? '',
  });
  if (typeof finish.error === 'string') throw new Error(finish.error);
  if (typeof finish.redirect !== 'string') throw new Error('Die Registrierung lieferte kein Weiterleitungsziel.');
  return finish.redirect;
}

export async function activateWithPasskey(label: string, csrfToken: string, mode: 'any' | 'hardware'): Promise<string> {
  const result = await post('/activate/options', csrfToken, { mode });
  if (typeof result.error === 'string') throw new Error(result.error);
  const options = result as unknown as PublicKeyCredentialCreationOptionsJSON;
  const publicKey: PublicKeyCredentialCreationOptions = {
    ...options,
    challenge: fromBase64Url(options.challenge),
    user: { ...options.user, id: fromBase64Url(options.user.id) },
    excludeCredentials: options.excludeCredentials?.map((item) => ({ ...item, id: fromBase64Url(item.id) })),
  };
  const credential = await navigator.credentials.create({ publicKey });
  if (!(credential instanceof PublicKeyCredential) || !(credential.response instanceof AuthenticatorAttestationResponse)) {
    throw new Error('Der Browser hat keinen gültigen FIDO2-Schlüssel erstellt.');
  }
  const finish = await post('/activate/finish', csrfToken, {
    label,
    credential: JSON.stringify(registrationPayload(credential, credential.response)),
    attachment: credential.authenticatorAttachment ?? '',
  });
  if (typeof finish.error === 'string') throw new Error(finish.error);
  if (typeof finish.redirect !== 'string') throw new Error('Aktivierung lieferte kein Weiterleitungsziel.');
  return finish.redirect;
}

function registrationPayload(credential: PublicKeyCredential, response: AuthenticatorAttestationResponse): Record<string, unknown> {
  return {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toBase64Url(response.clientDataJSON),
      attestationObject: toBase64Url(response.attestationObject),
      transports: response.getTransports?.() ?? [],
    },
  };
}

export async function stepUp(action: string, target: string, csrfToken: string, allowTotpFallback = true): Promise<void> {
  let fidoFailure: unknown;
  try {
    const result = await post('/admin/step-up/options', csrfToken, { action, target });
    if (typeof result.error === 'string') throw new Error(result.error);
    const options = result as unknown as PublicKeyCredentialRequestOptionsJSON;
    const publicKey: PublicKeyCredentialRequestOptions = {
      ...options,
      challenge: fromBase64Url(options.challenge),
      allowCredentials: options.allowCredentials?.map((item) => ({ ...item, id: fromBase64Url(item.id) })),
    };
    const credential = await navigator.credentials.get({ publicKey });
    if (!(credential instanceof PublicKeyCredential)) throw new Error('Kein FIDO2-Nachweis empfangen.');
    const verified = await post('/admin/step-up/finish', csrfToken, { credential: JSON.stringify(assertionPayload(credential)) });
    if (typeof verified.error === 'string') throw new Error(verified.error);
    if (verified.verified !== true) throw new Error('Step-up fehlgeschlagen.');
    return;
  } catch (error) {
    fidoFailure = error;
  }

  if (!allowTotpFallback || action === 'auth.recovery' || action === 'auth.policy.change') {
    const message = action === 'auth.recovery' || action === 'auth.policy.change'
      ? 'Für Recovery- und Richtlinienänderungen ist FIDO2-Step-up erforderlich.'
      : 'Diese Entfernung muss mit FIDO2-Step-up bestätigt werden.';
    throw new Error(message);
  }

  const code = window.prompt('FIDO2 war nicht verfügbar. Alternativ einen aktiven TOTP-Code eingeben:');
  if (code === null) {
    throw fidoFailure instanceof Error ? fidoFailure : new Error('Step-up abgebrochen.');
  }
  const result = await post('/admin/step-up/totp', csrfToken, { action, target, code });
  if (typeof result.error === 'string') throw new Error(result.error);
  if (result.verified !== true) throw new Error('Step-up fehlgeschlagen.');
}

interface PublicKeyCredentialDescriptorJSON extends Omit<PublicKeyCredentialDescriptor, 'id'> { id: string; }
interface PublicKeyCredentialRequestOptionsJSON extends Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'> {
  challenge: string;
  allowCredentials?: PublicKeyCredentialDescriptorJSON[];
}
interface PublicKeyCredentialUserEntityJSON extends Omit<PublicKeyCredentialUserEntity, 'id'> { id: string; }
interface PublicKeyCredentialCreationOptionsJSON extends Omit<PublicKeyCredentialCreationOptions, 'challenge' | 'user' | 'excludeCredentials'> {
  challenge: string;
  user: PublicKeyCredentialUserEntityJSON;
  excludeCredentials?: PublicKeyCredentialDescriptorJSON[];
}
