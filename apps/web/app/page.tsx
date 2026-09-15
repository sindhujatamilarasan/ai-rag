'use client';

import { useCallback, useEffect, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/* Design tokens — lifted from the VisionDesk mockup                    */
/* ------------------------------------------------------------------ */

const ACCENT = 'oklch(0.66 0.17 45)';
const ACCENT_DEEP = 'oklch(0.55 0.16 45)';

const LIGHT = {
  bg: '#f7f6f3',
  sf: '#ffffff',
  sf2: '#efede8',
  tx: '#17161a',
  mu: '#5f5c63',
  bd: 'rgba(0,0,0,.12)',
};

const DARK = {
  bg: '#121214',
  sf: '#1b1b1f',
  sf2: '#26262c',
  tx: '#f3f2f0',
  mu: '#a5a3aa',
  bd: 'rgba(255,255,255,.14)',
};

const FONT = "'Helvetica Neue', Helvetica, Arial, sans-serif";
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api';
const ORIGIN = API.replace(/\/api$/, '');

/* ------------------------------------------------------------------ */
/* Types — these mirror the Laravel API resources exactly              */
/* ------------------------------------------------------------------ */

type Bbox = { x: number; y: number; width: number; height: number };
type User = { id: number; name: string; email: string };
type DetectedObject = {
  id: number;
  label: string;
  confidence: number;
  tier: 'HIGH' | 'GOOD' | 'LOW';
  bbox: Bbox | null;
};

type Detection = {
  id: number;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  duration_seconds: number | null;
  total_tokens: number | null;
  error_message: string | null;
  objects?: DetectedObject[];
};

type ImageItem = {
  id: number;
  title: string | null;
  original_filename: string;
  url: string;
  width: number;
  height: number;
  captured_at: string;
  object_count?: number;
  latest_detection: Detection | null;
};

/* ------------------------------------------------------------------ */
/* Tiny API helper                                                      */
/* ------------------------------------------------------------------ */

function token() {
  return typeof window === 'undefined' ? null : localStorage.getItem('token');
}

async function call<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');

  const t = token();
  if (t) headers.set('Authorization', `Bearer ${t}`);

  // Never set Content-Type for FormData — the browser adds the multipart
  // boundary itself, and overriding it breaks the upload.
  if (init.body && !(init.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  const res = await fetch(`${API}${path}`, { ...init, headers });
  const data = await res.json().catch(() => ({}));

  if (!res.ok) throw new Error(data.message ?? `Request failed (${res.status})`);
  return data as T;
}

/* ------------------------------------------------------------------ */

type Screen = 'capture' | 'result' | 'history';

export default function VisionDesk() {
  const [dark, setDark] = useState(false);
  const [screen, setScreen] = useState<Screen>('capture');

  const [authed, setAuthed] = useState(false);
  const [images, setImages] = useState<ImageItem[]>([]);
  const [current, setCurrent] = useState<ImageItem | null>(null);
  const [error, setError] = useState('');
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);
  
  const c = dark ? DARK : LIGHT;


    useEffect(() => {
      setAuthed(!!token());
      setReady(true);
    }, []);

    useEffect(() => {
    if (!authed) return;
    call<User>('/me').then(setUser).catch(() => {});
  }, [authed]);

  async function logout() {
    try {
      await call('/logout', { method: 'POST' });
    } catch {
      /* token already invalid — clear locally either way */
    }
    localStorage.removeItem('token');
    setAuthed(false);
    setUser(null);
    setImages([]);
    setCurrent(null);
  }

  const loadImages = useCallback(async () => {
    try {
      const res = await call<{ data: ImageItem[] }>('/images');
      setImages(res.data);
    } catch (e) {
      setError((e as Error).message);
    }
  }, []);

  useEffect(() => {
    if (authed) loadImages();
  }, [authed, loadImages]);

  /* Poll while a detection is still running. Stops as soon as it settles. */
  useEffect(() => {
    const status = current?.latest_detection?.status;
    if (!current || status === 'completed' || status === 'failed') return;

    const id = setInterval(async () => {
      try {
        const res = await call<{ data: ImageItem }>(`/images/${current.id}`);
        setCurrent(res.data);
        if (res.data.latest_detection?.status === 'completed') loadImages();
      } catch {
        /* keep polling; a transient failure is not fatal */
      }
    }, 1500);

    return () => clearInterval(id);
  }, [current, loadImages]);
  if (!ready) {
    return (
      <div
        style={{
          minHeight: '100vh',
          background: c.bg,
          display: 'grid',
          placeItems: 'center',
        }}
      >
        <div style={{ animation: 'vd-pulse 1.1s ease-in-out infinite' }}>
          <Logo size={34} />
        </div>
        <style>{`@keyframes vd-pulse{0%,100%{opacity:.35}50%{opacity:.9}}`}</style>
      </div>
    );
  }
  if (!authed) {
    return <Login c={c} onDone={() => setAuthed(true)} />;
  }

  return (
    <div
      style={{
        minHeight: '100vh',
        background: c.bg,
        color: c.tx,
        fontFamily: FONT,
      }}
    >
      <header
        style={{
          position: 'sticky',
          top: 0,
          zIndex: 30,
          background: c.sf,
          borderBottom: `1px solid ${c.bd}`,
        }}
      >
        <div
          style={{
            maxWidth: 1240,
            margin: '0 auto',
            padding: '10px 14px',
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            flexWrap: 'wrap',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginRight: 'auto' }}>
              <Logo />            
              <span style={{ fontSize: 17, fontWeight: 700, letterSpacing: '-0.02em' }}>
              VisionDesk
            </span>
          </div>
          <Profile c={c} user={user} onLogout={logout} />
          <button
            onClick={() => setDark(!dark)}
            style={{
              minHeight: 44,
              padding: '0 16px',
              borderRadius: 11,
              border: `1px solid ${c.bd}`,
              background: c.sf2,
              color: c.tx,
              fontSize: 14,
              fontWeight: 600,
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: 8,
            }}
          >
            <span
              style={{ width: 12, height: 12, borderRadius: '50%', background: c.tx, display: 'block' }}
            />
            {dark ? 'Dark' : 'Light'}
          </button>

          <nav style={{ display: 'flex', gap: 6, order: 3, width: '100%' }}>
            {(['capture', 'result', 'history'] as Screen[]).map((s) => (
              <button
                key={s}
                onClick={() => setScreen(s)}
                style={{
                  flex: 1,
                  minHeight: 44,
                  borderRadius: 11,
                  border: `1px solid ${screen === s ? 'transparent' : c.bd}`,
                  background: screen === s ? ACCENT : c.sf,
                  color: screen === s ? '#fff' : c.tx,
                  fontSize: 14,
                  fontWeight: 600,
                  cursor: 'pointer',
                  textTransform: 'capitalize',
                }}
              >
                {s}
              </button>
            ))}
          </nav>
        </div>
      </header>

      <main style={{ maxWidth: 1240, margin: '0 auto', padding: '18px 14px 96px' }}>
        {error && (
          <div
            style={{
              marginBottom: 16,
              padding: '12px 14px',
              borderRadius: 13,
              background: 'oklch(0.66 0.19 25 / 0.12)',
              border: '1px solid oklch(0.66 0.19 25 / 0.4)',
              fontSize: 14,
            }}
          >
            {error}
          </div>
        )}

        {screen === 'capture' && (
          <Capture
            c={c}
            onUploaded={(img) => {
              setCurrent(img);
              setScreen('result');
              loadImages();
            }}
            onError={setError}
          />
        )}

        {screen === 'result' && <Result c={c} image={current} />}

        {screen === 'history' && (
          <History
            c={c}
            images={images}
            onOpen={async (img) => {
              const res = await call<{ data: ImageItem }>(`/images/${img.id}`);
              setCurrent(res.data);
              setScreen('result');
            }}
          />
        )}
      </main>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Login                                                                */
/* ------------------------------------------------------------------ */
function Login({ c, onDone }: { c: typeof LIGHT; onDone: () => void }) {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [showPw, setShowPw] = useState(false);
  const [err, setErr] = useState('');

  const isRegister = mode === 'register';

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErr('');

    try {
      const res = await call<{ token: string }>(isRegister ? '/register' : '/login', {
        method: 'POST',
        body: JSON.stringify(
          isRegister ? { name, email, password } : { email, password },
        ),
      });
      localStorage.setItem('token', res.token);
      onDone();
    } catch (e) {
      setErr((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const field: React.CSSProperties = {
    width: '100%',
    minHeight: 48,
    padding: '0 14px',
    borderRadius: 13,
    border: `1px solid ${c.bd}`,
    background: c.bg,
    color: c.tx,
    fontSize: 15,
    marginBottom: 12,
  };

  return (
    <div
      style={{
        minHeight: '100vh',
        background: c.bg,
        color: c.tx,
        fontFamily: FONT,
        display: 'grid',
        placeItems: 'center',
        padding: 20,
      }}
    >
      <form
        onSubmit={submit}
        style={{
          width: '100%',
          maxWidth: 400,
          background: c.sf,
          border: `1px solid ${c.bd}`,
          borderRadius: 18,
          padding: 24,
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 6 }}>
        <Logo />          
        <span style={{ fontSize: 17, fontWeight: 700 }}>VisionDesk</span>
        </div>

        <p style={{ margin: '0 0 18px', fontSize: 14, color: c.mu }}>
          {isRegister ? 'Create your account.' : 'Sign in to continue.'}
        </p>

        {isRegister && (
          <input
            style={field}
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Name"
            required
          />
        )}

        <input
          style={field}
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="Email"
          required
        />
        <div style={{ position: 'relative', marginBottom: 12 }}>
          <input
            style={{ ...field, marginBottom: 0, paddingRight: 48 }}
            type={showPw ? 'text' : 'password'}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="Password"
            minLength={8}
            required
          />
          <button
            type="button"
            onClick={() => setShowPw(!showPw)}
            aria-label={showPw ? 'Hide password' : 'Show password'}
            title={showPw ? 'Hide password' : 'Show password'}
            style={{
              position: 'absolute',
              right: 4,
              top: 0,
              height: '100%',
              width: 44,
              display: 'grid',
              placeItems: 'center',
              background: 'none',
              border: 'none',
              color: c.mu,
              cursor: 'pointer',
            }}
          >
            {showPw ? (
              <svg width="19" height="19" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22" />
              </svg>
            ) : (
              <svg width="19" height="19" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
            )}
          </button>
        </div>

        {err && (
          <p style={{ color: 'oklch(0.66 0.19 25)', fontSize: 14, margin: '0 0 12px' }}>{err}</p>
        )}

        <button
          type="submit"
          disabled={busy}
          style={{
            width: '100%',
            minHeight: 48,
            borderRadius: 13,
            border: 'none',
            background: busy ? c.sf2 : ACCENT,
            color: busy ? c.mu : '#fff',
            fontSize: 15,
            fontWeight: 700,
            cursor: busy ? 'default' : 'pointer',
          }}
        >
          {busy
            ? isRegister ? 'Creating…' : 'Signing in…'
            : isRegister ? 'Create account' : 'Sign in'}
        </button>

        <button
          type="button"
          onClick={() => {
            setMode(isRegister ? 'login' : 'register');
            setErr('');
          }}
          style={{
            width: '100%',
            marginTop: 14,
            background: 'none',
            border: 'none',
            color: c.mu,
            fontSize: 14,
            cursor: 'pointer',
          }}
        >
          {isRegister ? 'Already have an account? Sign in' : 'New here? Create an account'}
        </button>
      </form>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Capture                                                              */
/* ------------------------------------------------------------------ */

function Capture({
  c,
  onUploaded,
  onError,
}: {
  c: typeof LIGHT;
  onUploaded: (img: ImageItem) => void;
  onError: (m: string) => void;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  function pick(f: File | null) {
    setFile(f);
    setPreview(f ? URL.createObjectURL(f) : null);
  }

  async function upload() {
    if (!file) return;
    setBusy(true);

    try {
      const form = new FormData();
      form.append('image', file);
      form.append('title', file.name.replace(/\.[^.]+$/, ''));

      const res = await call<{ data: ImageItem; duplicate: boolean }>('/images', {
        method: 'POST',
        body: form,
      });

      pick(null);
      onUploaded(res.data);
    } catch (e) {
      onError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section>
      <h1 style={{ margin: '6px 0 4px', fontSize: 26, fontWeight: 700, letterSpacing: '-0.02em' }}>
        Capture
      </h1>
      <p style={{ margin: '0 0 18px', fontSize: 15, lineHeight: 1.5, color: c.mu, maxWidth: '52ch' }}>
        Point the camera at the work you want on record. VisionDesk tags what it sees so you can
        find the photo later by name.
      </p>

      <div
        style={{
          borderRadius: 18,
          overflow: 'hidden',
          border: `1px solid ${c.bd}`,
          background: c.sf,
        }}
      >
        <div
          style={{
            position: 'relative',
            aspectRatio: '4/3',
            background: c.sf2,
            display: 'grid',
            placeItems: 'center',
          }}
        >
          {preview ? (
            <img
              src={preview}
              alt=""
              style={{ width: '100%', height: '100%', objectFit: 'contain' }}
            />
          ) : (
            <span style={{ fontSize: 13, letterSpacing: '0.08em', color: c.mu }}>
              NO IMAGE SELECTED
            </span>
          )}
        </div>
      </div>

      {/* `capture="environment"` opens the rear camera on a phone and a normal
          file picker on desktop — one input covers both. */}
      <input
        ref={input}
        type="file"
        accept="image/*"
        capture="environment"
        hidden
        onChange={(e) => pick(e.target.files?.[0] ?? null)}
      />

      <div style={{ display: 'flex', gap: 10, marginTop: 16, flexWrap: 'wrap' }}>
        <button
          onClick={() => input.current?.click()}
          style={{
            flex: '1 1 200px',
            minHeight: 56,
            borderRadius: 15,
            border: 'none',
            background: ACCENT,
            color: '#fff',
            fontSize: 16,
            fontWeight: 700,
            cursor: 'pointer',
          }}
        >
          {file ? 'Choose another' : 'Capture or upload'}
        </button>

        {file && (
          <button
            onClick={upload}
            disabled={busy}
            style={{
              flex: '1 1 160px',
              minHeight: 56,
              borderRadius: 15,
              border: `1px solid ${c.bd}`,
              background: busy ? c.sf2 : c.sf,
              color: c.tx,
              fontSize: 16,
              fontWeight: 700,
              cursor: busy ? 'default' : 'pointer',
            }}
          >
            {busy ? 'Uploading…' : 'Detect'}
          </button>
        )}
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Result                                                               */
/* ------------------------------------------------------------------ */

function Result({ c, image }: { c: typeof LIGHT; image: ImageItem | null }) {
  const [active, setActive] = useState<number | null>(null);

  if (!image) {
    return <Empty c={c} text="Nothing selected yet. Capture an image or pick one from History." />;
  }

  const det = image.latest_detection;
  const objects = det?.objects ?? [];
  const running = det && det.status !== 'completed' && det.status !== 'failed';

  return (
    <section>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 10, flexWrap: 'wrap' }}>
        <h1 style={{ margin: '6px 0 14px', fontSize: 26, fontWeight: 700, letterSpacing: '-0.02em' }}>
          Detected objects
        </h1>
        <span style={{ fontSize: 13, color: c.mu, textTransform: 'uppercase', letterSpacing: '0.06em' }}>
          {image.original_filename}
        </span>
      </div>

      <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))' }}>
        {/* image + boxes */}
        <div>
          <div
            style={{
              position: 'relative',
              borderRadius: 16,
              overflow: 'hidden',
              border: `1px solid ${c.bd}`,
              background: c.sf2,
            }}
          >
            <img
              src={`${ORIGIN}${image.url}`}
              alt=""
              style={{ display: 'block', width: '100%' }}
            />

            {objects.map((o) =>
              o.bbox ? (
                <div
                  key={o.id}
                  onMouseEnter={() => setActive(o.id)}
                  onMouseLeave={() => setActive(null)}
                  style={{
                    position: 'absolute',
                    /* bbox values are normalized 0-1, so percentages work at
                       any rendered size — this is why they are not stored as pixels */
                    left: `${o.bbox.x * 100}%`,
                    top: `${o.bbox.y * 100}%`,
                    width: `${o.bbox.width * 100}%`,
                    height: `${o.bbox.height * 100}%`,
                    border: `2px solid ${active === o.id ? '#fff' : ACCENT}`,
                    borderRadius: 8,
                    boxShadow: active === o.id ? `0 0 0 3px ${ACCENT}` : 'none',
                    transition: 'box-shadow .12s, border-color .12s',
                  }}
                >
                  <span
                    style={{
                      position: 'absolute',
                      top: -22,
                      left: -2,
                      background: ACCENT,
                      color: '#fff',
                      fontSize: 12,
                      fontWeight: 600,
                      padding: '2px 7px',
                      borderRadius: 7,
                      whiteSpace: 'nowrap',
                    }}
                  >
                    {o.label} · {Math.round(o.confidence * 100)}%
                  </span>
                </div>
              ) : null,
            )}
          </div>

          <div
            style={{
              marginTop: 10,
              padding: '10px 14px',
              borderRadius: 13,
              border: `1px solid ${c.bd}`,
              background: c.sf,
              display: 'flex',
              gap: 18,
              flexWrap: 'wrap',
              fontSize: 12,
              color: c.mu,
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
            }}
          >
            <span>
              Captured <b style={{ color: c.tx }}>{new Date(image.captured_at).toLocaleString()}</b>
            </span>
            <span>
              Processed in{' '}
              <b style={{ color: c.tx }}>
                {det?.duration_seconds != null ? `${det.duration_seconds}s` : '—'}
              </b>
            </span>
            <span>
              Objects <b style={{ color: c.tx }}>{objects.length}</b>
            </span>
          </div>
        </div>

        {/* object list */}
        <div>
          <p
            style={{
              margin: '0 0 10px',
              fontSize: 12,
              color: c.mu,
              textTransform: 'uppercase',
              letterSpacing: '0.06em',
            }}
          >
            Objects found · highest confidence first
          </p>

          {running && <Pulse c={c} label={`Detection ${det?.status}…`} />}

          {det?.status === 'failed' && (
            <div
              style={{
                padding: '12px 14px',
                borderRadius: 13,
                background: 'oklch(0.66 0.19 25 / 0.12)',
                border: '1px solid oklch(0.66 0.19 25 / 0.4)',
                fontSize: 13,
              }}
            >
              {det.error_message ?? 'Detection failed.'}
            </div>
          )}

          {objects.map((o) => (
            <div
              key={o.id}
              onMouseEnter={() => setActive(o.id)}
              onMouseLeave={() => setActive(null)}
              style={{
                marginBottom: 8,
                padding: '11px 14px',
                borderRadius: 13,
                border: `1px solid ${active === o.id ? ACCENT : c.bd}`,
                background: c.sf,
                cursor: 'default',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span style={{ fontSize: 15, fontWeight: 600, marginRight: 'auto' }}>{o.label}</span>
                <span
                  style={{
                    fontSize: 11,
                    fontWeight: 700,
                    letterSpacing: '0.06em',
                    color: o.tier === 'HIGH' ? ACCENT_DEEP : c.mu,
                  }}
                >
                  {o.tier}
                </span>
                <span style={{ fontSize: 13, color: c.mu, minWidth: 38, textAlign: 'right' }}>
                  {Math.round(o.confidence * 100)}%
                </span>
              </div>

              <div
                style={{
                  marginTop: 8,
                  height: 6,
                  borderRadius: 999,
                  background: c.sf2,
                  overflow: 'hidden',
                }}
              >
                <div
                  style={{
                    width: `${o.confidence * 100}%`,
                    height: '100%',
                    background: ACCENT,
                    borderRadius: 999,
                  }}
                />
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* History                                                              */
/* ------------------------------------------------------------------ */

function History({
  c,
  images,
  onOpen,
}: {
  c: typeof LIGHT;
  images: ImageItem[];
  onOpen: (img: ImageItem) => void;
}) {
  const [q, setQ] = useState('');

  const shown = images.filter((i) => {
    if (!q.trim()) return true;
    const hay = [i.title ?? '', i.original_filename].join(' ').toLowerCase();
    return hay.includes(q.toLowerCase());
  });

  return (
    <section>
      <h1 style={{ margin: '6px 0 14px', fontSize: 26, fontWeight: 700, letterSpacing: '-0.02em' }}>
        History
      </h1>

      <div
        style={{
          padding: 14,
          borderRadius: 16,
          border: `1px solid ${c.bd}`,
          background: c.sf,
          marginBottom: 16,
        }}
      >
        <label
          style={{
            display: 'block',
            fontSize: 12,
            color: c.mu,
            textTransform: 'uppercase',
            letterSpacing: '0.06em',
            marginBottom: 6,
          }}
        >
          Search photos
        </label>
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="e.g. damaged pipe"
          style={{
            width: '100%',
            minHeight: 46,
            padding: '0 14px',
            borderRadius: 13,
            border: `1px solid ${c.bd}`,
            background: c.bg,
            color: c.tx,
            fontSize: 15,
          }}
        />
      </div>

      <p
        style={{
          margin: '0 0 10px',
          fontSize: 12,
          color: c.mu,
          textTransform: 'uppercase',
          letterSpacing: '0.06em',
        }}
      >
        {shown.length} of {images.length} photos
      </p>

      {shown.length === 0 ? (
        <Empty c={c} text="No photos yet. Capture one to get started." />
      ) : (
        <div
          style={{
            display: 'grid',
            gap: 14,
            gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))',
          }}
        >
          {shown.map((img) => (
            <button
              key={img.id}
              onClick={() => onOpen(img)}
              style={{
                textAlign: 'left',
                padding: 0,
                borderRadius: 16,
                overflow: 'hidden',
                border: `1px solid ${c.bd}`,
                background: c.sf,
                cursor: 'pointer',
              }}
            >
              <div style={{ position: 'relative', aspectRatio: '1/1', background: c.sf2 }}>
                <img
                  src={`${ORIGIN}${img.url}`}
                  alt=""
                  style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                />
                <span
                  style={{
                    position: 'absolute',
                    top: 8,
                    right: 8,
                    background: 'rgba(0,0,0,.66)',
                    color: '#fff',
                    fontSize: 11,
                    fontWeight: 700,
                    padding: '3px 8px',
                    borderRadius: 999,
                  }}
                >
                  {img.object_count ?? 0} obj
                </span>
              </div>

              <div style={{ padding: '10px 12px 12px' }}>
                <p style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>
                  {img.title ?? img.original_filename}
                </p>
                <p style={{ margin: '3px 0 0', fontSize: 12, color: c.mu }}>
                  {new Date(img.captured_at).toLocaleDateString()}
                </p>
              </div>
            </button>
          ))}
        </div>
      )}
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Small shared pieces                                                  */
/* ------------------------------------------------------------------ */

function Empty({ c, text }: { c: typeof LIGHT; text: string }) {
  return (
    <div
      style={{
        padding: '40px 20px',
        borderRadius: 16,
        border: `1px dashed ${c.bd}`,
        background: c.sf,
        textAlign: 'center',
        fontSize: 14,
        color: c.mu,
      }}
    >
      {text}
    </div>
  );
}

function Pulse({ c, label }: { c: typeof LIGHT; label: string }) {
  return (
    <div
      style={{
        padding: '12px 14px',
        borderRadius: 13,
        border: `1px solid ${c.bd}`,
        background: c.sf,
        fontSize: 14,
        color: c.mu,
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        marginBottom: 10,
      }}
    >
      <span
        style={{
          width: 10,
          height: 10,
          borderRadius: '50%',
          background: ACCENT,
          animation: 'vd-pulse 1.1s ease-in-out infinite',
        }}
      />
      {label}
      <style>{`@keyframes vd-pulse{0%,100%{opacity:.35}50%{opacity:.9}}`}</style>
    </div>
  );
}
function Profile({
  c,
  user,
  onLogout,
}: {
  c: typeof LIGHT;
  user: User | null;
  onLogout: () => void;
}) {
  const [open, setOpen] = useState(false);

  if (!user) return null;

  const initial = user.name.trim().charAt(0).toUpperCase();

  return (
    <div style={{ position: 'relative' }}>
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        style={{
          minHeight: 44,
          padding: '0 12px 0 6px',
          borderRadius: 11,
          border: `1px solid ${c.bd}`,
          background: c.sf2,
          color: c.tx,
          fontSize: 14,
          fontWeight: 600,
          cursor: 'pointer',
          display: 'flex',
          alignItems: 'center',
          gap: 9,
        }}
      >
        <span
          style={{
            width: 30,
            height: 30,
            borderRadius: '50%',
            background: ACCENT,
            color: '#fff',
            display: 'grid',
            placeItems: 'center',
            fontSize: 13,
            fontWeight: 700,
          }}
        >
          {initial}
        </span>
        {user.name.split(' ')[0]}
      </button>

      {open && (
        <>
          {/* click-outside catcher — sits behind the menu, above everything else */}
          <div
            onClick={() => setOpen(false)}
            style={{ position: 'fixed', inset: 0, zIndex: 40 }}
          />

          <div
            style={{
              position: 'absolute',
              top: 'calc(100% + 8px)',
              right: 0,
              zIndex: 41,
              minWidth: 240,
              background: c.sf,
              border: `1px solid ${c.bd}`,
              borderRadius: 15,
              padding: 14,
              boxShadow: '0 12px 32px rgba(0,0,0,.14)',
            }}
          >
            <p style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>{user.name}</p>
            <p style={{ margin: '3px 0 0', fontSize: 13, color: c.mu, wordBreak: 'break-all' }}>
              {user.email}
            </p>

            <div style={{ height: 1, background: c.bd, margin: '13px 0' }} />

            <button
              onClick={onLogout}
              style={{
                width: '100%',
                minHeight: 42,
                borderRadius: 11,
                border: `1px solid ${c.bd}`,
                background: c.bg,
                color: 'oklch(0.66 0.19 25)',
                fontSize: 14,
                fontWeight: 600,
                cursor: 'pointer',
              }}
            >
              Sign out
            </button>
          </div>
        </>
      )}
    </div>
  );
}
function Logo({ size = 26 }: { size?: number }) {
  return (
    <div
      aria-hidden="true"
      style={{
        width: size,
        height: size,
        borderRadius: size * 0.27,
        background: ACCENT,
        color: '#fff',
        display: 'grid',
        placeItems: 'center',
        fontSize: size * 0.42,
        fontWeight: 800,
        letterSpacing: '-0.04em',
        lineHeight: 1,
        fontFamily: FONT,
        userSelect: 'none',
      }}
    >
      VD
    </div>
  );
}