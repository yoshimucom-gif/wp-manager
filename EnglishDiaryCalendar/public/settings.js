const defaultLevelSelect = document.querySelector("#defaultLevelSelect");
const saveSettingsButton = document.querySelector("#saveSettingsButton");
const settingsStatus = document.querySelector("#settingsStatus");

const settingsKey = "english-diary-calendar:settings";

function loadSettings() {
  return JSON.parse(localStorage.getItem(settingsKey) || "{}");
}

function saveSettings(settings) {
  localStorage.setItem(settingsKey, JSON.stringify(settings));
}

const settings = loadSettings();
defaultLevelSelect.value = settings.defaultTargetLevel || "toeic600";

saveSettingsButton.addEventListener("click", () => {
  saveSettings({
    ...loadSettings(),
    defaultTargetLevel: defaultLevelSelect.value
  });
  settingsStatus.textContent = "保存しました。";
});
