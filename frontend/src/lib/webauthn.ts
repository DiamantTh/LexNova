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

export async function loginWithPasskey(csrfToken: string): Promise<string> {
  const result = await post('/admin/passkeys/login/options', csrfToken, {});
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
  const payload = {
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
  const finish = await post('/admin/passkeys/login/finish', csrfToken, { credential: JSON.stringify(payload) });
  if (typeof finish.error === 'string') throw new Error(finish.error);
  if (typeof finish.redirect !== 'string') throw new Error('Die Anmeldung lieferte kein Weiterleitungsziel.');
  return finish.redirect;
}

export async function registerPasskey(userId: number, label: string, csrfToken: string): Promise<string> {
  const result = await post('/admin/passkeys/register/options', csrfToken, { user_id: String(userId) });
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
  const payload = {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toBase64Url(response.clientDataJSON),
      attestationObject: toBase64Url(response.attestationObject),
      transports: response.getTransports?.() ?? [],
    },
  };
  const finish = await post('/admin/passkeys/register/finish', csrfToken, {
    label,
    credential: JSON.stringify(payload),
    attachment: credential.authenticatorAttachment ?? '',
  });
  if (typeof finish.error === 'string') throw new Error(finish.error);
  if (typeof finish.redirect !== 'string') throw new Error('Die Registrierung lieferte kein Weiterleitungsziel.');
  return finish.redirect;
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
