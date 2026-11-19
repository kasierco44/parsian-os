#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

#[tauri::command]
fn save_session(session_json: String) -> Result<(), String> {
    let entry = keyring::Entry::new("ir.pgtparts.parsian", "session")
        .map_err(|error| error.to_string())?;
    entry.set_password(&session_json).map_err(|error| error.to_string())
}

#[tauri::command]
fn load_session() -> Result<Option<String>, String> {
    let entry = keyring::Entry::new("ir.pgtparts.parsian", "session")
        .map_err(|error| error.to_string())?;
    match entry.get_password() {
        Ok(value) => Ok(Some(value)),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(error) => Err(error.to_string()),
    }
}

#[tauri::command]
fn clear_session() -> Result<(), String> {
    let entry = keyring::Entry::new("ir.pgtparts.parsian", "session")
        .map_err(|error| error.to_string())?;
    match entry.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(error) => Err(error.to_string()),
    }
}

fn main() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![save_session, load_session, clear_session])
        .run(tauri::generate_context!())
        .expect("error while running PARSIAN OS desktop shell");
}
