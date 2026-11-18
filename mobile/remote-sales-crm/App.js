import React, { useEffect, useMemo, useState } from "react";
import { getCustomers, getMe, getStoredToken, isApiConfigured, login as apiLogin, logout as apiLogout, changePassword as apiChangePassword } from "./src/api/client";
import { cacheRecord, readCachedRecords, getConflictedOperations } from "./src/offline/database";
import { queueOfflineChange, synchronize } from "./src/api/sync";
import { synchronizeCatalog } from "./src/catalog/catalog";
import {
  I18nManager, KeyboardAvoidingView, Modal, Platform, Pressable,
  SafeAreaView, ScrollView, StatusBar, StyleSheet, Text, TextInput, View, Image
} from "react-native";
import { StatusBar as ExpoStatusBar } from "expo-status-bar";
import * as Network from "expo-network";

I18nManager.allowRTL(true);

const C = {
  bg: "#F4F5F7", ink: "#17191F", muted: "#777D89", red: "#D62828",
  redSoft: "#FCE8E8", card: "#FFFFFF", line: "#E9EAEE",
  green: "#16845B", greenSoft: "#E5F5EE", amber: "#A66B12", amberSoft: "#FFF3D8",
  dark: "#17191F", blue: "#3157A4"
};

const initialCustomers = [
  { id: "1", name: "پخش قطعات رضایی", city: "قم", phone: "09122551001", status: "فعال", last: "امروز، ۱۰:۳۰", due: "پیگیری قیمت وایر ۴۰۵" },
  { id: "2", name: "لوازم یدکی امینی", city: "اراک", phone: "09123662002", status: "نیازمند پیگیری", last: "دیروز", due: "ارسال پیش‌فاکتور" },
  { id: "3", name: "پخش خودرو مرکزی", city: "همدان", phone: "09128123003", status: "فعال", last: "۲ روز پیش", due: "تماس برای سفارش جدید" },
  { id: "4", name: "یدک‌گستر غرب", city: "کرمانشاه", phone: "09183334004", status: "جدید", last: "ثبت نشده", due: "تماس اولیه" }
];

const money = (n) => new Intl.NumberFormat("fa-IR").format(n);
const Tab = ({ icon, label, active, onPress }) => (
  <Pressable onPress={onPress} style={styles.tab}>
    <Text style={[styles.tabIcon, active && { color: C.red }]}>{icon}</Text>
    <Text style={[styles.tabLabel, active && { color: C.red, fontWeight: "900" }]}>{label}</Text>
  </Pressable>
);
const Pill = ({ children, tone = "neutral" }) => (
  <View style={[styles.pill, tone === "green" && { backgroundColor: C.greenSoft }, tone === "red" && { backgroundColor: C.redSoft }, tone === "amber" && { backgroundColor: C.amberSoft }]}>
    <Text style={[styles.pillText, tone === "green" && { color: C.green }, tone === "red" && { color: C.red }, tone === "amber" && { color: C.amber }]}>{children}</Text>
  </View>
);
const SectionTitle = ({ title, action, onAction }) => (
  <View style={styles.sectionTitle}>
    <Text style={styles.h2}>{title}</Text>
    {action ? <Pressable onPress={onAction}><Text style={styles.link}>{action}</Text></Pressable> : null}
  </View>
);

export default function App() {
  const [entered, setEntered] = useState(false);
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [apiToken, setApiToken] = useState(null);
  const [apiUser, setApiUser] = useState(null);
  const [apiMode, setApiMode] = useState(false);
  const [loginBusy, setLoginBusy] = useState(false);
  const [loginError, setLoginError] = useState("");
  const [syncBusy, setSyncBusy] = useState(false);
  const [syncConflictCount, setSyncConflictCount] = useState(0);
  const [passwordChangeRequired, setPasswordChangeRequired] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [tab, setTab] = useState("خانه");
  const [customers, setCustomers] = useState(initialCustomers);
  const [products, setProducts] = useState([]);
  const [proformas, setProformas] = useState([]);
  const [visits, setVisits] = useState([]);
  const [visitModal, setVisitModal] = useState(false);
  const [visitNote, setVisitNote] = useState("");
  const [visitOutcome, setVisitOutcome] = useState("مذاکره و معرفی محصول");
  const [proformaModal, setProformaModal] = useState(false);
  const [proformaProductId, setProformaProductId] = useState("");
  const [proformaQuantity, setProformaQuantity] = useState("1");
  const [search, setSearch] = useState("");
  const [modal, setModal] = useState(false);
  const [customerModal, setCustomerModal] = useState(false);
  const [newCustomerName, setNewCustomerName] = useState("");
  const [newCustomerPhone, setNewCustomerPhone] = useState("");
  const [newCustomerCity, setNewCustomerCity] = useState("");
  const [newCustomerProvince, setNewCustomerProvince] = useState("");
  const [selected, setSelected] = useState(null);
  const [note, setNote] = useState("");
  const [toast, setToast] = useState("");
  const [todayTasks, setTodayTasks] = useState([
    { id: 1, title: "تماس با لوازم یدکی امینی", time: "۱۰:۰۰", done: false },
    { id: 2, title: "ارسال قیمت وایر استاندارد دنسو", time: "۱۲:۳۰", done: false },
    { id: 3, title: "پیگیری وصول فاکتور مشتری قم", time: "۱۵:۰۰", done: true }
  ]);

  const filtered = useMemo(() => customers.filter(x => (x.name + (x.city || "") + (x.phone || "")).includes(search.trim())), [customers, search]);
  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const [cachedCustomers, cachedTasks, cachedProducts, cachedProformas, cachedVisits, conflicts] = await Promise.all([
          readCachedRecords("customer"),
          readCachedRecords("task"),
          readCachedRecords("product"),
          readCachedRecords("proforma_draft"),
          readCachedRecords("visit"),
          getConflictedOperations(),
        ]);
        if (!active) return;
        if (cachedCustomers.length) setCustomers(cachedCustomers);
        if (cachedTasks.length) setTodayTasks(cachedTasks);
        if (cachedProducts.length) setProducts(cachedProducts);
        if (cachedProformas.length) setProformas(cachedProformas);
        if (cachedVisits.length) setVisits(cachedVisits);
        setSyncConflictCount(conflicts.length);
      } catch {
        // A fresh install may not have created the local database yet.
      }
    })();
    return () => { active = false; };
  }, []);

  const saveCustomerCache = async (rows) => {
    await Promise.all(rows.map(customer => cacheRecord("customer", String(customer.id), customer)));
  };

  const makeUuid = () => {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, c => {
      const r = Math.floor(Math.random() * 16);
      return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
    });
  };

  const addCustomer = async () => {
    const name = newCustomerName.trim();
    if (name.length < 2) {
      showToast("نام مشتری را حداقل با دو نویسه وارد کنید");
      return;
    }
    const id = makeUuid();
    const payload = {
      name,
      phone: newCustomerPhone.trim() || null,
      city: newCustomerCity.trim() || null,
      province: newCustomerProvince.trim() || null,
      customer_type: "wholesaler",
      notes: null,
    };
    const localCustomer = {
      id, ...payload, status: "فعال", is_active: true,
      last: "ثبت روی دستگاه", due: "نیازمند پیگیری",
    };
    try {
      await cacheRecord("customer", id, localCustomer);
      if (apiToken) {
        await queueOfflineChange({ entityType: "customer", entityId: id, operation: "upsert", payload });
      }
      setCustomers(previous => [localCustomer, ...previous.filter(item => String(item.id) !== id)]);
      setCustomerModal(false);
      setNewCustomerName(""); setNewCustomerPhone(""); setNewCustomerCity(""); setNewCustomerProvince("");
      showToast(apiToken ? "مشتری ذخیره شد؛ با اتصال اینترنت همگام می‌شود" : "مشتری به‌صورت محلی ذخیره شد");
    } catch (error) {
      showToast(error.message || "ذخیره مشتری ناموفق بود");
    }
  };


  useEffect(() => {
    if (!apiToken) return undefined;
    let syncing = false;
    const syncWhenOnline = async (state) => {
      if (!state?.isConnected || state.isInternetReachable === false || syncing) return;
      syncing = true;
      try {
        await synchronize({ token: apiToken });
        const tasks = await readCachedRecords("task");
        if (tasks.length) setTodayTasks(tasks);
        const localProformas = await readCachedRecords("proforma_draft");
        if (localProformas.length) setProformas(localProformas);
        const localVisits = await readCachedRecords("visit");
        if (localVisits.length) setVisits(localVisits);
        const latestProducts = await synchronizeCatalog(apiToken);
        setProducts(latestProducts);
        const serverCustomers = await getCustomers({ token: apiToken });
        const mappedCustomers = serverCustomers.map(c => ({
          id: c.id, name: c.name, city: c.city || c.province || "",
          phone: c.phone || "", status: c.is_active ? "فعال" : "غیرفعال",
          last: "از سرور", due: "—",
        }));
        await saveCustomerCache(mappedCustomers);
        setCustomers(previous => {
          const serverIds = new Set(mappedCustomers.map(c => String(c.id)));
          return [...mappedCustomers, ...previous.filter(c => !serverIds.has(String(c.id)) && String(c.id).length === 36)];
        });
      } catch {
        // Keep the outbox and cached records intact; the next network event retries.
      } finally {
        syncing = false;
      }
    };
    const subscription = Network.addNetworkStateListener(syncWhenOnline);
    Network.getNetworkStateAsync().then(syncWhenOnline).catch(() => {});
    return () => subscription.remove();
  }, [apiToken]);



  const toggleTask = async (taskId) => {
    const next = todayTasks.map(task => task.id === taskId ? { ...task, done: !task.done } : task);
    const changed = next.find(task => task.id === taskId);
    setTodayTasks(next);
    if (!changed) return;
    try {
      await cacheRecord("task", String(changed.id), changed);
      await queueOfflineChange({
        entityType: "task",
        entityId: String(changed.id),
        operation: "upsert",
        payload: changed,
      });
    } catch (error) {
      showToast(error.message || "ذخیره محلی انجام نشد");
    }
  };


  useEffect(() => {
    let active = true;
    (async () => {
      if (!isApiConfigured()) return;
      let token = null;
      try {
        token = await getStoredToken();
        if (!token || !active) return;
        const me = await getMe(token);
        if (!active) return;
        setApiToken(token); setApiUser(me); setApiMode(true); setEntered(true);
        const rows = await getCustomers({ token });
        if (active && Array.isArray(rows)) { const mapped = rows.map(c => ({ id: c.id, name: c.name, city: c.city || c.province || "", phone: c.mobile || c.phone || "", status: c.is_active ? "فعال" : "غیرفعال", last: "از سرور", due: "—" })); setCustomers(mapped); await saveCustomerCache(mapped); }
      } catch (e) {
        if (e.status === 403 && String(e.detail || "").toLowerCase().includes("password change required")) {
          if (active) { setApiToken(token); setPasswordChangeRequired(true); }
        } else if (e.status === 0) {
          if (active) { setApiToken(token); setApiMode(true); setEntered(true); }
        } else {
          await apiLogout();
        }
      }
    })();
    return () => { active = false; };
  }, []);

  const submitLogin = async () => {
    setLoginError("");
    if (!isApiConfigured()) { setLoginError("اتصال سرور هنوز برای این نسخه تنظیم نشده است. برای بررسی ظاهر از ورود نمایشی استفاده کنید."); return; }
    if (!username.trim() || !password) { setLoginError("نام کاربری و رمز عبور را وارد کنید."); return; }
    setLoginBusy(true);
    try {
      const result = await apiLogin(username, password);
      let me;
      try {
        me = await getMe(result.access_token);
      } catch (e) {
        if (e.status === 403 && String(e.detail || "").toLowerCase().includes("password change required")) {
          setApiToken(result.access_token);
          setPasswordChangeRequired(true);
          setLoginError("");
          return;
        }
        throw e;
      }
      const rows = await getCustomers({ token: result.access_token });
      setApiToken(result.access_token); setApiUser(me); setApiMode(true);
      setCustomers(rows.map(c => ({ id: c.id, name: c.name, city: c.city || c.province || "", phone: c.mobile || c.phone || "", status: c.is_active ? "فعال" : "غیرفعال", last: "از سرور", due: "—" })));
      await saveCustomerCache(rows.map(c => ({ id: c.id, name: c.name, city: c.city || c.province || "", phone: c.mobile || c.phone || "", status: c.is_active ? "فعال" : "غیرفعال", last: "از سرور", due: "—" })));
      setEntered(true);
    } catch (e) { setLoginError(e.message || "ورود ناموفق بود."); }
    finally { setLoginBusy(false); }
  };

  const submitPasswordChange = async () => {
    setLoginError("");
    if (!currentPassword || newPassword.length < 12 || newPassword !== confirmPassword) {
      setLoginError("رمز فعلی را وارد کنید؛ رمز جدید باید حداقل ۱۲ نویسه باشد و تکرار آن یکسان باشد.");
      return;
    }
    setLoginBusy(true);
    try {
      await apiChangePassword({ token: apiToken, currentPassword, newPassword });
      const me = await getMe(apiToken);
      const rows = await getCustomers({ token: apiToken });
      setApiUser(me); setApiMode(true); setPasswordChangeRequired(false);
      setCustomers(rows.map(c => ({ id: c.id, name: c.name, city: c.city || c.province || "", phone: c.mobile || c.phone || "", status: c.is_active ? "فعال" : "غیرفعال", last: "از سرور", due: "—" })));
      await saveCustomerCache(rows.map(c => ({ id: c.id, name: c.name, city: c.city || c.province || "", phone: c.mobile || c.phone || "", status: c.is_active ? "فعال" : "غیرفعال", last: "از سرور", due: "—" })));
      setEntered(true); setCurrentPassword(""); setNewPassword(""); setConfirmPassword("");
    } catch (e) { setLoginError(e.message || "تغییر رمز عبور انجام نشد."); }
    finally { setLoginBusy(false); }
  };

  const syncProductCatalog = async () => {
    if (!apiToken) {
      showToast("برای دریافت کاتالوگ به حساب سازمانی وارد شوید");
      return;
    }
    setSyncBusy(true);
    try {
      const latest = await synchronizeCatalog(apiToken);
      setProducts(latest);
      showToast("کاتالوگ و تصاویر محصولات به‌روز شد");
    } catch (error) {
      const cached = await readCachedRecords("product").catch(() => []);
      setProducts(cached);
      showToast(error.message || "کاتالوگ آفلاین نمایش داده می‌شود");
    } finally {
      setSyncBusy(false);
    }
  };

  const syncCustomers = async () => {
    if (!apiToken) return;
    setSyncBusy(true);
    try {
      const syncResult = await synchronize({ token: apiToken });
      setSyncConflictCount((await getConflictedOperations()).length);
      const rows = await getCustomers({ token: apiToken, query: search });
      const mapped = rows.map(c => ({ id: c.id, name: c.name, city: c.city || c.province || "", phone: c.mobile || c.phone || "", status: c.is_active ? "فعال" : "غیرفعال", last: "از سرور", due: "—" }));
      setCustomers(mapped);
      await saveCustomerCache(mapped);
      showToast("همگام‌سازی و دریافت مشتریان انجام شد");
    } catch (e) {
      try {
        const cached = await readCachedRecords("customer");
        if (cached.length) setCustomers(cached);
      } catch {}
      showToast(e.message || "اتصال برقرار نیست؛ داده‌های ذخیره‌شده نمایش داده شدند");
    }
    finally { setSyncBusy(false); }
  };
  const showToast = (msg) => { setToast(msg); setTimeout(() => setToast(""), 2200); };
  const addVisitReport = async () => {
    if (!selected) {
      showToast("ابتدا مشتری را انتخاب کنید");
      return;
    }
    if (visitNote.trim().length < 3) {
      showToast("شرح ویزیت را وارد کنید");
      return;
    }
    const id = makeUuid();
    const payload = {
      customer_id: String(selected.id),
      customer_name: selected.name,
      outcome: visitOutcome,
      note: visitNote.trim(),
      visited_at: new Date().toISOString(),
    };
    const visit = { id, ...payload, sync_status: apiToken ? "در صف همگام‌سازی" : "فقط روی دستگاه" };
    try {
      await cacheRecord("visit", id, visit);
      if (apiToken) await queueOfflineChange({ entityType: "visit", entityId: id, operation: "upsert", payload });
      setVisits(previous => [visit, ...previous]);
      setVisitModal(false);
      setVisitNote("");
      showToast("گزارش ویزیت ذخیره شد");
      setTab("پیگیری‌ها");
    } catch (error) {
      showToast(error.message || "ذخیره گزارش ویزیت ناموفق بود");
    }
  };

  const addProformaDraft = async () => {
    const product = products.find(item => String(item.id) === String(proformaProductId));
    const quantity = Math.max(1, Math.min(100000, Number.parseInt(proformaQuantity, 10) || 1));
    if (!selected) {
      showToast("ابتدا مشتری را انتخاب کنید");
      return;
    }
    if (!product) {
      showToast("یک محصول از کاتالوگ انتخاب کنید");
      return;
    }
    const id = makeUuid();
    const unitPrice = product.unit_price == null ? null : Number(product.unit_price);
    const payload = {
      customer_id: String(selected.id),
      customer_name: selected.name,
      items: [{
        product_id: String(product.id),
        sku: product.sku,
        name: product.name,
        quantity,
        unit_price: unitPrice,
        line_total: unitPrice == null ? null : unitPrice * quantity,
      }],
      status: "draft",
      created_at: new Date().toISOString(),
      note: "پیش‌نویس؛ نیازمند تأیید سرور",
    };
    const draft = {
      id,
      local_reference: "DRAFT-" + id.slice(0, 8).toUpperCase(),
      ...payload,
      total: unitPrice == null ? null : unitPrice * quantity,
    };
    try {
      await cacheRecord("proforma_draft", id, draft);
      if (apiToken) {
        await queueOfflineChange({ entityType: "proforma_draft", entityId: id, operation: "upsert", payload });
      }
      setProformas(previous => [draft, ...previous]);
      setProformaModal(false);
      setProformaProductId("");
      setProformaQuantity("1");
      showToast("پیش‌فاکتور به‌صورت پیش‌نویس ذخیره شد؛ قطعی نیست");
      setTab("پیش‌فاکتور");
    } catch (error) {
      showToast(error.message || "ذخیره پیش‌فاکتور ناموفق بود");
    }
  };

  const addFollowup = async () => {
    if (!note.trim()) return;
    const task = { id: String(Date.now()), title: note.trim(), time: "جدید", done: false };
    try {
      await cacheRecord("task", task.id, task);
      await queueOfflineChange({ entityType: "task", entityId: task.id, operation: "upsert", payload: task });
      setTodayTasks(prev => [task, ...prev]);
      setNote(""); setModal(false);
      showToast("پیگیری روی دستگاه ذخیره شد و پس از اتصال همگام می‌شود");
    } catch (error) {
      showToast(error.message || "ذخیره پیگیری ناموفق بود");
    }
  };

  if (!entered && passwordChangeRequired) return (
    <SafeAreaView style={styles.login}>
      <ExpoStatusBar style="light" />
      <View style={styles.brandMark}><Text style={styles.brandMarkText}>پ</Text></View>
      <Text style={styles.brandTitle}>تغییر رمز عبور</Text>
      <Text style={styles.brandSub}>برای امنیت حساب، ابتدا رمز موقت را تغییر دهید.</Text>
      <View style={styles.loginCard}>
        <Text style={styles.fieldLabel}>رمز فعلی / موقت</Text>
        <TextInput style={styles.input} value={currentPassword} onChangeText={setCurrentPassword} secureTextEntry textAlign="right" />
        <Text style={styles.fieldLabel}>رمز جدید (حداقل ۱۲ نویسه)</Text>
        <TextInput style={styles.input} value={newPassword} onChangeText={setNewPassword} secureTextEntry textAlign="right" />
        <Text style={styles.fieldLabel}>تکرار رمز جدید</Text>
        <TextInput style={styles.input} value={confirmPassword} onChangeText={setConfirmPassword} secureTextEntry textAlign="right" />
        {loginError ? <Text style={styles.loginError}>{loginError}</Text> : null}
        <Pressable style={styles.primaryBtn} disabled={loginBusy} onPress={submitPasswordChange}><Text style={styles.primaryBtnText}>{loginBusy ? "در حال ثبت..." : "ثبت رمز جدید و ورود"}</Text></Pressable>
      </View>
    </SafeAreaView>
  );

  if (!entered) return (
    <SafeAreaView style={styles.login}>
      <ExpoStatusBar style="light" />
      <View style={styles.brandMark}><Text style={styles.brandMarkText}>پ</Text></View>
      <Text style={styles.brandTitle}>پارسیان‌یار</Text>
      <Text style={styles.brandSub}>سامانه ارتباط و فروش ویزیتورهای دورکار</Text>
      <View style={styles.loginCard}>
        <Text style={styles.loginHeading}>ورود به پنل ویزیتور</Text>
        <Text style={styles.fieldLabel}>نام کاربری</Text>
        <TextInput style={styles.input} value={username} onChangeText={setUsername} textAlign="right" autoCapitalize="none" placeholder="نام کاربری سازمانی" />
        <Text style={styles.fieldLabel}>رمز عبور</Text>
        <TextInput style={styles.input} value={password} onChangeText={setPassword} textAlign="right" secureTextEntry placeholder="رمز عبور" />
        {loginError ? <Text style={styles.loginError}>{loginError}</Text> : null}
        <Pressable style={[styles.primaryBtn, loginBusy && { opacity: 0.6 }]} disabled={loginBusy} onPress={submitLogin}><Text style={styles.primaryBtnText}>{loginBusy ? "در حال ورود..." : "ورود با حساب سازمانی"}</Text></Pressable>
        <Pressable style={styles.outlineBtn} onPress={() => { setApiMode(false); setEntered(true); }}><Text style={styles.outlineBtnText}>ورود نمایشی برای بررسی محیط</Text></Pressable>
        <Text style={styles.demoHint}>{isApiConfigured() ? "اتصال API تنظیم شده است؛ برای ورود واقعی از حساب ایجادشده توسط مدیر استفاده کنید." : "اتصال API هنوز روی سرور/نسخه نصب‌شده تنظیم نشده است. ورود نمایشی فقط برای بررسی ظاهر است."}</Text>
      </View>
      <Text style={styles.version}>نسخه آزمایشی ۰.۱.۰</Text>
    </SafeAreaView>
  );

  const header = (
    <View style={styles.header}>
      <View>
        <Text style={styles.headerEyebrow}>پارسیان‌یار | پنل ویزیتور</Text>
        <Text style={styles.headerTitle}>{tab === "خانه" ? `سلام، ${apiUser?.full_name || "ویزیتور عزیز"}` : tab}</Text>
      </View>
      <View style={styles.avatar}><Text style={styles.avatarText}>و</Text></View>
    </View>
  );

  const dashboard = (
    <>
      <View style={styles.targetCard}>
        <View style={styles.targetTop}><View><Text style={styles.targetCaption}>هدف فروش ماه جاری</Text><Text style={styles.targetValue}>{money(10000)} <Text style={styles.targetUnit}>دست</Text></Text></View><Pill tone="green">عملکرد رو به رشد</Pill></View>
        <View style={styles.progressTrack}><View style={styles.progressFill} /></View>
        <View style={styles.progressMeta}><Text style={styles.targetSmall}>۱٬۸۵۰ دست فروش ثبت‌شده</Text><Text style={styles.targetSmall}>۱۸.۵٪ تحقق هدف</Text></View>
      </View>
      <View style={styles.metricGrid}>
        <View style={styles.metricCard}><Text style={styles.metricIcon}>▣</Text><Text style={styles.metricValue}>{money(24)}</Text><Text style={styles.metricLabel}>مشتری فعال</Text></View>
        <View style={styles.metricCard}><Text style={styles.metricIcon}>↗</Text><Text style={styles.metricValue}>{money(8)}</Text><Text style={styles.metricLabel}>سفارش این ماه</Text></View>
        <View style={styles.metricCard}><Text style={styles.metricIcon}>◷</Text><Text style={styles.metricValue}>{money(6)}</Text><Text style={styles.metricLabel}>پیگیری باز</Text></View>
        <View style={styles.metricCard}><Text style={styles.metricIcon}>٪</Text><Text style={styles.metricValue}>{money(12.4)}٪</Text><Text style={styles.metricLabel}>پورسانت تخمینی</Text></View>
      </View>
      <SectionTitle title="برنامه امروز" action="افزودن پیگیری" onAction={() => setModal(true)} />
      <View style={styles.card}>
        {todayTasks.map((task, i) => <Pressable key={task.id} style={[styles.taskRow, i !== 0 && styles.topLine]} onPress={() => toggleTask(task.id)}>
          <View style={[styles.check, task.done && styles.checked]}>{task.done ? <Text style={styles.checkMark}>✓</Text> : null}</View>
          <View style={styles.taskBody}><Text style={[styles.taskTitle, task.done && styles.doneText]}>{task.title}</Text><Text style={styles.taskTime}>{task.time}</Text></View>
        </Pressable>)}
      </View>
      <SectionTitle title="آخرین مشتریان" action="همه مشتریان" onAction={() => setTab("مشتریان")} />
      {customers.slice(0, 3).map(c => <CustomerCard key={c.id} item={c} onPress={() => { setSelected(c); setTab("جزئیات مشتری"); }} />)}
    </>
  );

  function CustomerCard({ item, onPress }) {
    return <Pressable style={styles.customerCard} onPress={onPress}>
      <View style={styles.customerAvatar}><Text style={styles.customerAvatarText}>{item.name.slice(0, 1)}</Text></View>
      <View style={styles.customerInfo}><Text style={styles.customerName}>{item.name}</Text><Text style={styles.customerMeta}>{item.city}  •  {item.last}</Text></View>
      <Text style={styles.chevron}>‹</Text>
    </Pressable>;
  }

  const customersScreen = (
    <>
      <View style={styles.searchBox}><Text style={styles.searchIcon}>⌕</Text><TextInput placeholder="جست‌وجوی نام، شهر یا شماره تماس" placeholderTextColor="#9A9EAA" value={search} onChangeText={setSearch} style={styles.searchInput} textAlign="right" /></View>
      <View style={styles.filterRow}><Pill tone="red">همه ({money(customers.length)})</Pill><Pill>فعال</Pill><Pill tone="amber">نیازمند پیگیری</Pill></View>
      <SectionTitle title="فهرست مشتریان" action="＋ مشتری جدید" onAction={() => setCustomerModal(true)} />
      {filtered.map(c => <CustomerCard key={c.id} item={c} onPress={() => { setSelected(c); setTab("جزئیات مشتری"); }} />)}
      {filtered.length === 0 && <Text style={styles.empty}>مشتری‌ای با این مشخصات پیدا نشد.</Text>}
    </>
  );

  const followupsScreen = (
    <>
      <View style={styles.metricStrip}><Text style={styles.metricStripBig}>{money(todayTasks.filter(t => !t.done).length)}</Text><Text style={styles.metricStripText}>پیگیری باقی‌مانده</Text><Pressable style={styles.smallRedBtn} onPress={() => setModal(true)}><Text style={styles.smallRedText}>+ جدید</Text></Pressable></View>
      {todayTasks.map((task, i) => <Pressable key={task.id} style={styles.followCard} onPress={() => toggleTask(task.id)}><View style={[styles.check, task.done && styles.checked]}>{task.done ? <Text style={styles.checkMark}>✓</Text> : null}</View><View style={styles.taskBody}><Text style={styles.taskTitle}>{task.title}</Text><Text style={styles.taskTime}>{task.time}</Text></View><Pill tone={task.done ? "green" : "amber"}>{task.done ? "انجام شد" : "باز"}</Pill></Pressable>)}
      <SectionTitle title="گزارش‌های ویزیت" action="ثبت گزارش" onAction={() => { if (!selected && customers.length) setSelected(customers[0]); setVisitModal(true); }} />
      {visits.map(visit => (
        <View key={visit.id} style={styles.proformaCard}>
          <Text style={styles.productName}>{visit.customer_name}</Text>
          <Text style={styles.productMeta}>{visit.outcome} • {new Date(visit.visited_at).toLocaleDateString("fa-IR")}</Text>
          <Text style={styles.productDescription}>{visit.note}</Text>
        </View>
      ))}
      {visits.length === 0 ? <Text style={styles.empty}>گزارش ویزیتی ثبت نشده است.</Text> : null}
    </>
  );

  const detailScreen = selected ? (
    <>
      <Pressable onPress={() => setTab("مشتریان")}><Text style={styles.link}>‹ بازگشت به مشتریان</Text></Pressable>
      <View style={styles.detailHero}><View style={styles.bigAvatar}><Text style={styles.bigAvatarText}>{selected.name.slice(0, 1)}</Text></View><Text style={styles.detailName}>{selected.name}</Text><Text style={styles.customerMeta}>{selected.city}  •  {selected.phone}</Text><Pill tone="green">{selected.status}</Pill></View>
      <View style={styles.card}><Info label="آخرین ارتباط" value={selected.last} /><Info label="اقدام بعدی" value={selected.due} /><Info label="نوع مشتری" value="پخش / عمده‌فروش" /><Info label="مانده حساب" value="اطلاعات نمونه؛ متصل نیست" /></View>
      <SectionTitle title="اقدامات سریع" />
      <Pressable style={styles.primaryBtn} onPress={() => setModal(true)}><Text style={styles.primaryBtnText}>＋ ثبت پیگیری</Text></Pressable>
      <Pressable style={styles.outlineBtn} onPress={() => { setProformaProductId(products[0]?.id ? String(products[0].id) : ""); setProformaModal(true); }}><Text style={styles.outlineBtnText}>ثبت پیش‌فاکتور (پیش‌نویس)</Text></Pressable>
      <Pressable style={styles.outlineBtn} onPress={() => setVisitModal(true)}><Text style={styles.outlineBtnText}>ثبت گزارش ویزیت</Text></Pressable>
    </>
  ) : null;

  function Info({ label, value }) {
    return <View style={styles.infoRow}><Text style={styles.infoValue}>{value}</Text><Text style={styles.infoLabel}>{label}</Text></View>;
  }

  const proformaScreen = (
    <>
      <SectionTitle title="پیش‌فاکتورهای پیش‌نویس" action="کاتالوگ محصولات" onAction={() => setTab("محصولات")} />
      <Text style={styles.catalogHint}>این اسناد پیش‌نویس هستند؛ شماره فاکتور قطعی، موجودی و مانده حساب فقط پس از تأیید سرور ثبت می‌شوند.</Text>
      {proformas.map(item => (
        <View key={item.id} style={styles.proformaCard}>
          <View style={styles.proformaTop}><Text style={styles.productMeta}>{item.local_reference}</Text><Pill tone="amber">پیش‌نویس</Pill></View>
          <Text style={styles.productName}>{item.customer_name}</Text>
          {(item.items || []).map((line, index) => <Text key={index} style={styles.productMeta}>{line.name} × {line.quantity}</Text>)}
          <Text style={styles.productPrice}>{item.total == null ? "قیمت در کاتالوگ ثبت نشده" : "جمع پیش‌نویس: " + money(item.total)}</Text>
        </View>
      ))}
      {proformas.length === 0 ? <Text style={styles.empty}>هنوز پیش‌فاکتوری ثبت نشده است. از صفحه جزئیات مشتری می‌توانید پیش‌نویس بسازید.</Text> : null}
    </>
  );

  const productsScreen = (
    <>
      <SectionTitle title="کاتالوگ محصولات پارسیان قطعه" action={syncBusy ? "در حال دریافت..." : "به‌روزرسانی"} onAction={syncProductCatalog} />
      <Text style={styles.catalogHint}>محصولات و تصاویر دریافت‌شده روی دستگاه ذخیره می‌شوند و بدون اینترنت نیز قابل مشاهده‌اند.</Text>
      {products.map(product => (
        <View key={product.id} style={styles.productCard}>
          {product.image_uri ? <Image source={{ uri: product.image_uri }} style={styles.productImage} resizeMode="contain" /> : <View style={styles.productImagePlaceholder}><Text style={styles.productPlaceholderText}>پارسیان</Text></View>}
          <View style={styles.productInfo}>
            <Text style={styles.productName}>{product.name}</Text>
            <Text style={styles.productMeta}>کد: {product.sku}  •  {product.vehicle_model || product.category}</Text>
            {product.unit_price != null ? <Text style={styles.productPrice}>{money(product.unit_price)} ریال</Text> : <Text style={styles.productMeta}>قیمت در کاتالوگ ثبت نشده</Text>}
            {product.description ? <Text style={styles.productDescription}>{product.description}</Text> : null}
          </View>
        </View>
      ))}
      {products.length === 0 ? <Text style={styles.empty}>هنوز محصولی دریافت نشده است. پس از انتشار کاتالوگ توسط مدیر، این بخش به‌روزرسانی می‌شود.</Text> : null}
    </>
  );

  const profileScreen = (
    <>
      <View style={styles.detailHero}><View style={styles.bigAvatar}><Text style={styles.bigAvatarText}>و</Text></View><Text style={styles.detailName}>ویزیتور نمونه</Text><Text style={styles.customerMeta}>کد پرسنلی: VIS-001</Text><Pill tone="green">حساب آزمایشی</Pill></View>
      <View style={styles.card}><Info label="هدف ماهانه" value="۱۰٬۰۰۰ دست" /><Info label="فروش ثبت‌شده" value="۱٬۸۵۰ دست" /><Info label="پورسانت تخمینی" value="۱۲.۴٪" /><Info label="وضعیت اتصال" value="آفلاین / داده نمونه" /></View>
      <Pressable style={styles.outlineBtn} onPress={async () => { if (apiMode) await apiLogout(); setApiToken(null); setApiUser(null); setApiMode(false); setEntered(false); setTab("خانه"); }}><Text style={styles.outlineBtnText}>خروج از حساب</Text></Pressable>
    </>
  );

  let content = tab === "خانه" ? dashboard : tab === "مشتریان" ? customersScreen : tab === "پیگیری‌ها" ? followupsScreen : tab === "محصولات" ? productsScreen : tab === "پیش‌فاکتور" ? proformaScreen : tab === "جزئیات مشتری" ? detailScreen : profileScreen;

  return (
    <SafeAreaView style={styles.app}>
      <ExpoStatusBar style="dark" />
      {header}
      <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <View style={styles.demoBanner}><Text style={styles.demoBannerText}>{apiMode ? "اتصال به سرور • اطلاعات مشتریان از حساب شما" : "محیط آزمایشی • اطلاعات این نسخه نمونه است"}</Text></View>
        {syncConflictCount > 0 ? <Pressable style={styles.conflictBanner} onPress={() => showToast("تعارض همگام‌سازی حفظ شده است؛ برای رفع آن باید نسخه‌ها بررسی شوند.")}><Text style={styles.conflictText}>{money(syncConflictCount)} مورد تغییر همگام‌سازی‌نشده نیازمند بررسی است</Text></Pressable> : null}
        {content}
      </ScrollView>
      <View style={styles.bottomNav}>
        <Tab icon="⌂" label="خانه" active={tab === "خانه"} onPress={() => setTab("خانه")} />
        <Tab icon="♙" label="مشتریان" active={tab === "مشتریان"} onPress={() => setTab("مشتریان")} />
        <Tab icon="◷" label="پیگیری‌ها" active={tab === "پیگیری‌ها"} onPress={() => setTab("پیگیری‌ها")} />
        <Tab icon="▣" label="محصولات" active={tab === "محصولات"} onPress={() => setTab("محصولات")} />
        <Tab icon="▤" label="پیش‌فاکتور" active={tab === "پیش‌فاکتور"} onPress={() => setTab("پیش‌فاکتور")} />
        <Tab icon="☻" label="حساب من" active={tab === "حساب من"} onPress={() => setTab("حساب من")} />
      </View>
      {toast ? <View style={styles.toast}><Text style={styles.toastText}>{toast}</Text></View> : null}
      <Modal visible={modal} transparent animationType="fade" onRequestClose={() => setModal(false)}>
        <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={styles.modalBackdrop}>
          <View style={styles.modalCard}><Text style={styles.modalTitle}>ثبت پیگیری جدید</Text><Text style={styles.fieldLabel}>شرح پیگیری</Text><TextInput value={note} onChangeText={setNote} placeholder="مثلاً تماس برای پیگیری سفارش..." textAlign="right" multiline style={[styles.input, styles.noteInput]} /><Pressable style={styles.primaryBtn} onPress={addFollowup}><Text style={styles.primaryBtnText}>ثبت پیگیری</Text></Pressable><Pressable style={styles.cancelBtn} onPress={() => setModal(false)}><Text style={styles.cancelText}>انصراف</Text></Pressable></View>
        </KeyboardAvoidingView>
      </Modal>
      <Modal visible={visitModal} transparent animationType="fade" onRequestClose={() => setVisitModal(false)}>
        <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>گزارش ویزیت مشتری</Text>
            <Text style={styles.fieldLabel}>مشتری</Text>
            <Text style={styles.input}>{selected?.name || "مشتری انتخاب نشده"}</Text>
            <Text style={styles.fieldLabel}>نتیجه ویزیت</Text>
            <TextInput value={visitOutcome} onChangeText={setVisitOutcome} textAlign="right" style={styles.input} />
            <Text style={styles.fieldLabel}>شرح ویزیت</Text>
            <TextInput value={visitNote} onChangeText={setVisitNote} placeholder="مذاکرات، نیاز مشتری، اقدام بعدی..." textAlign="right" multiline style={[styles.input, styles.noteInput]} />
            <Pressable style={styles.primaryBtn} onPress={addVisitReport}><Text style={styles.primaryBtnText}>ذخیره گزارش</Text></Pressable>
            <Pressable style={styles.cancelBtn} onPress={() => setVisitModal(false)}><Text style={styles.cancelText}>انصراف</Text></Pressable>
          </View>
        </KeyboardAvoidingView>
      </Modal>
      <Modal visible={proformaModal} transparent animationType="fade" onRequestClose={() => setProformaModal(false)}>
        <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>پیش‌نویس پیش‌فاکتور</Text>
            <Text style={styles.fieldLabel}>مشتری</Text>
            <Text style={styles.input}>{selected?.name || "مشتری انتخاب نشده"}</Text>
            <Text style={styles.fieldLabel}>محصول</Text>
            <ScrollView style={{ maxHeight: 170 }}>
              {products.map(product => (
                <Pressable key={product.id} style={[styles.productChoice, String(product.id) === String(proformaProductId) && styles.productChoiceActive]} onPress={() => setProformaProductId(String(product.id))}>
                  <Text style={styles.productChoiceText}>{product.name}</Text>
                </Pressable>
              ))}
            </ScrollView>
            <Text style={styles.fieldLabel}>تعداد</Text>
            <TextInput value={proformaQuantity} onChangeText={setProformaQuantity} keyboardType="number-pad" textAlign="right" style={styles.input} />
            <Pressable style={styles.primaryBtn} onPress={addProformaDraft}><Text style={styles.primaryBtnText}>ذخیره پیش‌نویس</Text></Pressable>
            <Pressable style={styles.cancelBtn} onPress={() => setProformaModal(false)}><Text style={styles.cancelText}>انصراف</Text></Pressable>
          </View>
        </KeyboardAvoidingView>
      </Modal>
      <Modal visible={customerModal} transparent animationType="fade" onRequestClose={() => setCustomerModal(false)}>
        <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>ثبت مشتری جدید</Text>
            <Text style={styles.fieldLabel}>نام فروشگاه / مشتری *</Text>
            <TextInput value={newCustomerName} onChangeText={setNewCustomerName} placeholder="مثلاً پخش قطعات..." textAlign="right" style={styles.input} />
            <Text style={styles.fieldLabel}>شماره تماس</Text>
            <TextInput value={newCustomerPhone} onChangeText={setNewCustomerPhone} keyboardType="phone-pad" placeholder="شماره تماس" textAlign="right" style={styles.input} />
            <Text style={styles.fieldLabel}>شهر</Text>
            <TextInput value={newCustomerCity} onChangeText={setNewCustomerCity} placeholder="شهر" textAlign="right" style={styles.input} />
            <Text style={styles.fieldLabel}>استان</Text>
            <TextInput value={newCustomerProvince} onChangeText={setNewCustomerProvince} placeholder="استان" textAlign="right" style={styles.input} />
            <Pressable style={styles.primaryBtn} onPress={addCustomer}><Text style={styles.primaryBtnText}>ذخیره مشتری</Text></Pressable>
            <Pressable style={styles.cancelBtn} onPress={() => setCustomerModal(false)}><Text style={styles.cancelText}>انصراف</Text></Pressable>
          </View>
        </KeyboardAvoidingView>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  app: { flex: 1, backgroundColor: C.bg, paddingTop: Platform.OS === "android" ? StatusBar.currentHeight || 0 : 0 },
  login: { flex: 1, backgroundColor: C.dark, justifyContent: "center", alignItems: "center", padding: 24 },
  brandMark: { width: 70, height: 70, borderRadius: 22, backgroundColor: C.red, alignItems: "center", justifyContent: "center", marginBottom: 14 },
  brandMarkText: { color: "#fff", fontSize: 38, fontWeight: "900" },
  brandTitle: { color: "#fff", fontSize: 27, fontWeight: "900" },
  brandSub: { color: "#B7BBC4", fontSize: 13, marginTop: 8, marginBottom: 28 },
  loginCard: { backgroundColor: "#fff", width: "100%", borderRadius: 22, padding: 20 },
  loginHeading: { color: C.ink, fontSize: 18, fontWeight: "900", textAlign: "right", marginBottom: 18 },
  fieldLabel: { color: C.muted, fontSize: 12, textAlign: "right", marginBottom: 7, marginTop: 8 },
  input: { borderWidth: 1, borderColor: C.line, backgroundColor: "#FAFAFB", borderRadius: 12, paddingHorizontal: 13, paddingVertical: 12, color: C.ink, fontSize: 14 },
  primaryBtn: { backgroundColor: C.red, borderRadius: 13, minHeight: 48, alignItems: "center", justifyContent: "center", marginTop: 14 },
  primaryBtnText: { color: "#fff", fontSize: 14, fontWeight: "900" },
  demoHint: { color: C.muted, fontSize: 11, textAlign: "center", lineHeight: 19, marginTop: 15 },
  version: { color: "#9B9FA9", fontSize: 11, marginTop: 24 },
  loginError: { color: C.red, fontSize: 11, textAlign: "right", marginTop: 10 },
  header: { backgroundColor: "#fff", paddingHorizontal: 20, paddingTop: 12, paddingBottom: 15, flexDirection: "row-reverse", alignItems: "center", justifyContent: "space-between", borderBottomWidth: 1, borderColor: C.line },
  headerEyebrow: { color: C.muted, fontSize: 11, textAlign: "right" },
  headerTitle: { color: C.ink, fontSize: 19, fontWeight: "900", textAlign: "right", marginTop: 4 },
  avatar: { width: 43, height: 43, borderRadius: 15, backgroundColor: C.redSoft, alignItems: "center", justifyContent: "center" },
  avatarText: { color: C.red, fontSize: 20, fontWeight: "900" },
  content: { padding: 16, paddingBottom: 24 },
  demoBanner: { backgroundColor: "#FFF3D8", borderRadius: 10, padding: 9, marginBottom: 14 },
  demoBannerText: { color: C.amber, fontSize: 11, fontWeight: "700", textAlign: "center" },
  targetCard: { backgroundColor: C.dark, borderRadius: 18, padding: 17, marginBottom: 14 },
  targetTop: { flexDirection: "row-reverse", justifyContent: "space-between", alignItems: "flex-start" },
  targetCaption: { color: "#B9BDC6", fontSize: 12, textAlign: "right" },
  targetValue: { color: "#fff", fontSize: 29, fontWeight: "900", textAlign: "right", marginTop: 7 },
  targetUnit: { color: "#D6D8DE", fontSize: 13, fontWeight: "500" },
  progressTrack: { height: 8, backgroundColor: "#41444D", borderRadius: 8, marginTop: 18, overflow: "hidden" },
  progressFill: { width: "18.5%", height: 8, backgroundColor: C.red, borderRadius: 8 },
  progressMeta: { flexDirection: "row-reverse", justifyContent: "space-between", marginTop: 9 },
  targetSmall: { color: "#D4D6DC", fontSize: 10 },
  pill: { backgroundColor: "#EEF0F3", paddingHorizontal: 9, paddingVertical: 5, borderRadius: 20, alignSelf: "flex-start" },
  pillText: { color: C.muted, fontSize: 10, fontWeight: "700" },
  metricGrid: { flexDirection: "row-reverse", flexWrap: "wrap", justifyContent: "space-between", marginBottom: 17 },
  metricCard: { width: "48.3%", backgroundColor: "#fff", borderRadius: 15, padding: 13, marginBottom: 10, borderWidth: 1, borderColor: C.line },
  metricIcon: { color: C.red, fontSize: 18, textAlign: "right" },
  metricValue: { color: C.ink, fontSize: 21, fontWeight: "900", textAlign: "right", marginTop: 5 },
  metricLabel: { color: C.muted, fontSize: 11, textAlign: "right", marginTop: 3 },
  sectionTitle: { flexDirection: "row-reverse", alignItems: "center", justifyContent: "space-between", marginTop: 9, marginBottom: 10 },
  h2: { color: C.ink, fontSize: 15, fontWeight: "900", textAlign: "right" },
  link: { color: C.red, fontSize: 11, fontWeight: "800", textAlign: "right" },
  card: { backgroundColor: "#fff", borderRadius: 15, paddingHorizontal: 14, marginBottom: 16, borderWidth: 1, borderColor: C.line },
  taskRow: { flexDirection: "row-reverse", alignItems: "center", paddingVertical: 13 },
  topLine: { borderTopWidth: 1, borderColor: C.line },
  check: { width: 22, height: 22, borderRadius: 7, borderWidth: 1.5, borderColor: "#C9CDD5", alignItems: "center", justifyContent: "center" },
  checked: { backgroundColor: C.green, borderColor: C.green },
  checkMark: { color: "#fff", fontWeight: "900", fontSize: 13 },
  taskBody: { flex: 1, marginHorizontal: 10 },
  taskTitle: { color: C.ink, fontSize: 12, fontWeight: "700", textAlign: "right" },
  taskTime: { color: C.muted, fontSize: 10, textAlign: "right", marginTop: 4 },
  doneText: { color: "#9DA2AD", textDecorationLine: "line-through" },
  customerCard: { backgroundColor: "#fff", borderRadius: 14, padding: 12, marginBottom: 9, flexDirection: "row-reverse", alignItems: "center", borderWidth: 1, borderColor: C.line },
  customerAvatar: { width: 39, height: 39, borderRadius: 13, backgroundColor: C.redSoft, alignItems: "center", justifyContent: "center" },
  customerAvatarText: { color: C.red, fontSize: 17, fontWeight: "900" },
  customerInfo: { flex: 1, marginHorizontal: 10 },
  customerName: { color: C.ink, fontSize: 12, fontWeight: "900", textAlign: "right" },
  customerMeta: { color: C.muted, fontSize: 10, textAlign: "right", marginTop: 5 },
  chevron: { color: "#A5A9B2", fontSize: 24 },
  searchBox: { backgroundColor: "#fff", borderWidth: 1, borderColor: C.line, borderRadius: 13, paddingHorizontal: 12, flexDirection: "row-reverse", alignItems: "center", marginBottom: 12 },
  searchIcon: { color: C.muted, fontSize: 23, marginLeft: 8 },
  searchInput: { flex: 1, paddingVertical: 13, color: C.ink, fontSize: 12 },
  filterRow: { flexDirection: "row-reverse", gap: 8, marginBottom: 13 },
  empty: { color: C.muted, textAlign: "center", marginTop: 35 },
  metricStrip: { flexDirection: "row-reverse", alignItems: "center", backgroundColor: "#fff", borderRadius: 14, padding: 14, marginBottom: 12 },
  metricStripBig: { color: C.red, fontSize: 24, fontWeight: "900" },
  metricStripText: { color: C.ink, fontSize: 12, marginRight: 9, flex: 1 },
  smallRedBtn: { backgroundColor: C.redSoft, borderRadius: 9, paddingHorizontal: 12, paddingVertical: 8 },
  smallRedText: { color: C.red, fontSize: 11, fontWeight: "900" },
  followCard: { backgroundColor: "#fff", borderRadius: 14, padding: 13, marginBottom: 9, flexDirection: "row-reverse", alignItems: "center", borderWidth: 1, borderColor: C.line },
  detailHero: { alignItems: "center", backgroundColor: "#fff", padding: 20, borderRadius: 16, marginTop: 14, marginBottom: 14, borderWidth: 1, borderColor: C.line },
  bigAvatar: { width: 66, height: 66, borderRadius: 22, backgroundColor: C.redSoft, alignItems: "center", justifyContent: "center", marginBottom: 10 },
  bigAvatarText: { color: C.red, fontSize: 30, fontWeight: "900" },
  detailName: { color: C.ink, fontSize: 17, fontWeight: "900", marginBottom: 6 },
  infoRow: { flexDirection: "row-reverse", justifyContent: "space-between", paddingVertical: 13, borderBottomWidth: 1, borderColor: C.line },
  infoLabel: { color: C.muted, fontSize: 11 },
  infoValue: { color: C.ink, fontSize: 11, fontWeight: "700", textAlign: "left", flex: 1, marginRight: 12 },
  outlineBtn: { borderWidth: 1, borderColor: C.red, borderRadius: 13, minHeight: 47, alignItems: "center", justifyContent: "center", marginTop: 10, backgroundColor: "#fff" },
  outlineBtnText: { color: C.red, fontSize: 13, fontWeight: "900" },
  bottomNav: { backgroundColor: "#fff", borderTopWidth: 1, borderColor: C.line, flexDirection: "row-reverse", justifyContent: "space-around", paddingTop: 9, paddingBottom: Platform.OS === "android" ? 10 : 18 },
  tab: { minWidth: 62, minHeight: 45, alignItems: "center", justifyContent: "center" },
  tabIcon: { color: "#8B909B", fontSize: 19 },
  tabLabel: { color: "#8B909B", fontSize: 9, marginTop: 3 },
  conflictBanner: { backgroundColor: "#FCE8E8", borderRadius: 10, padding: 10, marginBottom: 12 },
  conflictText: { color: C.red, fontSize: 11, fontWeight: "800", textAlign: "center" },
  catalogHint: { color: C.muted, fontSize: 11, textAlign: "right", marginBottom: 12 },
  productChoice: { borderWidth: 1, borderColor: C.line, borderRadius: 9, padding: 10, marginTop: 6 },
  productChoiceActive: { borderColor: C.red, backgroundColor: C.redSoft },
  productChoiceText: { color: C.ink, textAlign: "right", fontSize: 12 },
  proformaCard: { backgroundColor: "#fff", borderRadius: 14, padding: 13, marginBottom: 10, borderWidth: 1, borderColor: C.line },
  proformaTop: { flexDirection: "row-reverse", justifyContent: "space-between", alignItems: "center", marginBottom: 8 },
  productCard: { backgroundColor: "#fff", borderRadius: 14, padding: 12, marginBottom: 10, flexDirection: "row-reverse", alignItems: "center", borderWidth: 1, borderColor: C.line },
  productImage: { width: 82, height: 82, backgroundColor: "#fff", borderRadius: 10 },
  productImagePlaceholder: { width: 82, height: 82, borderRadius: 10, backgroundColor: C.redSoft, alignItems: "center", justifyContent: "center" },
  productPlaceholderText: { color: C.red, fontSize: 12, fontWeight: "900" },
  productInfo: { flex: 1, marginRight: 12 },
  productName: { color: C.ink, fontSize: 13, fontWeight: "900", textAlign: "right" },
  productMeta: { color: C.muted, fontSize: 10, textAlign: "right", marginTop: 5 },
  productPrice: { color: C.red, fontSize: 12, fontWeight: "900", textAlign: "right", marginTop: 5 },
  productDescription: { color: C.muted, fontSize: 10, textAlign: "right", marginTop: 5 },
  toast: { position: "absolute", bottom: 78, alignSelf: "center", backgroundColor: C.dark, paddingHorizontal: 18, paddingVertical: 11, borderRadius: 12 },
  toastText: { color: "#fff", fontSize: 12 },
  modalBackdrop: { flex: 1, backgroundColor: "#0008", justifyContent: "center", padding: 22 },
  modalCard: { backgroundColor: "#fff", borderRadius: 18, padding: 18 },
  modalTitle: { color: C.ink, fontSize: 17, fontWeight: "900", textAlign: "right", marginBottom: 10 },
  noteInput: { minHeight: 90, textAlignVertical: "top" },
  cancelBtn: { minHeight: 44, alignItems: "center", justifyContent: "center", marginTop: 5 },
  cancelText: { color: C.muted, fontSize: 12, fontWeight: "700" }
});
