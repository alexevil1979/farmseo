import { Api } from '../api.js';
import { el } from '../utils.js';
import { toast } from '../components/toast.js';

export function renderLogin({ onSuccess }) {
  const root = el('div', { class: 'login-wrap' });
  const err = el('div', { class: 'login-error' });
  const email = el('input', { class: 'input', type: 'email', autocomplete: 'username', value: '' });
  const password = el('input', { class: 'input', type: 'password', autocomplete: 'current-password' });
  const totp = el('input', { class: 'input', type: 'text', placeholder: 'если включён 2FA', autocomplete: 'one-time-code' });
  const submit = el('button', { class: 'btn btn-primary', type: 'submit', text: 'Войти', style: { width: '100%', marginTop: '8px' } });

  const form = el('form', {}, [
    el('h1', { class: 'brand', html: `${window.FarmConfig?.appName || 'Farm'}<span> Admin</span>` }),
    el('p', { class: 'sub', text: 'Панель управления фермой профилей' }),
    err,
    el('div', { class: 'field' }, [el('label', { text: 'Email' }), email]),
    el('div', { class: 'field' }, [el('label', { text: 'Пароль' }), password]),
    el('div', { class: 'field' }, [el('label', { text: 'TOTP' }), totp]),
    submit,
  ]);

  form.onsubmit = async (e) => {
    e.preventDefault();
    err.textContent = '';
    submit.disabled = true;
    try {
      const res = await Api.login(email.value.trim(), password.value, totp.value.trim() || undefined);
      if (res.totp_required) {
        err.textContent = 'Введите код 2FA';
        return;
      }
      const data = res.data || res;
      Api.setAuth({
        token: data.token,
        csrf: data.csrf,
        user: data.user,
        role: data.role,
        expires_in: data.expires_in,
      });
      toast('Вход выполнен');
      onSuccess(data);
    } catch (ex) {
      err.textContent = ex.message || 'Ошибка входа';
    } finally {
      submit.disabled = false;
    }
  };

  root.append(el('div', { class: 'login-card' }, [form]));
  return root;
}
