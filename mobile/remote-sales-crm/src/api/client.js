import * as SecureStore from "expo-secure-store";

const API_BASE_URL = (process.env.EXPO_PUBLIC_API_BASE_URL || "").replace(/\/+$/, "");
const TOKEN_KEY = "parsian.remote-sales.access-token";

export class ApiError extends Error {
  constructor(message, status = 0, detail = null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.detail = detail;
  }
}

export function isApiConfigured() {
  return Boolean(API_BASE_URL);
}

async function request(path, { method = "GET", body, token, headers = {} } = {}) {
  if (!API_BASE_URL) {
    throw new ApiError("نشانی API تنظیم نشده است. EXPO_PUBLIC_API_BASE_URL را تعیین کنید.");
  }

  let response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers: {
        Accept: "application/json",
        ...(body ? { "Content-Type": "application/json" } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...headers,
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
  } catch {
    throw new ApiError("ارتباط با سرور برقرار نشد. اتصال اینترنت و نشانی سرور را بررسی کنید.");
  }

  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    const detail = payload?.detail ?? null;
    const message =
      response.status === 401 ? "نام کاربری یا رمز عبور نامعتبر است یا نشست شما پایان یافته است."
      : response.status === 403 ? "شما مجوز دسترسی به این بخش را ندارید."
      : typeof detail === "string" ? detail
      : "درخواست به سرور ناموفق بود.";
    throw new ApiError(message, response.status, detail);
  }
  return payload;
}

export async function login(username, password) {
  const result = await request("/auth/login", {
    method: "POST",
    body: { username: username.trim(), password },
  });
  if (!result?.access_token) throw new ApiError("پاسخ ورود سرور معتبر نیست.");
  await SecureStore.setItemAsync(TOKEN_KEY, result.access_token);
  return result;
}

export async function getStoredToken() {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export async function getMe(token) {
  return request("/auth/me", { token });
}

export async function getCustomers({ token, query = "", limit = 50, offset = 0 } = {}) {
  const params = new URLSearchParams({ limit: String(limit), offset: String(offset) });
  if (query.trim()) params.set("q", query.trim());
  return request(`/customers?${params.toString()}`, { token });
}

export async function logout() {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}

export async function changePassword({ token, currentPassword, newPassword }) {
  return request("/auth/password/change", {
    method: "POST",
    token,
    body: { current_password: currentPassword, new_password: newPassword },
  });
}
