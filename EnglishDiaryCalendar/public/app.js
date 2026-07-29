import { recordUsage } from "./usage-tracker.js";

const calendarGrid = document.querySelector("#calendarGrid");
const monthLabel = document.querySelector("#monthLabel");
const selectedDateLabel = document.querySelector("#selectedDateLabel");
const japaneseInput = document.querySelector("#japaneseInput");
const userEnglishInput = document.querySelector("#userEnglishInput");
const targetLevelSelect = document.querySelector("#targetLevelSelect");
const correctedInput = document.querySelector("#correctedInput");
const translationInput = document.querySelector("#translationInput");
const correctionList = document.querySelector("#correctionList");
const notesList = document.querySelector("#notesList");
const vocabList = document.querySelector("#vocabList");
const shadowingList = document.querySelector("#shadowingList");
const alternativesList = document.querySelector("#alternativesList");
const reviewList = document.querySelector("#reviewList");
const chatLog = document.querySelector("#chatLog");
const chatInput = document.querySelector("#chatInput");
const chatSendButton = document.querySelector("#chatSendButton");
const statusEl = document.querySelector("#status");
const topicText = document.querySelector("#topicText");
const topicButton = document.querySelector("#topicButton");
const translateButton = document.querySelector("#translateButton");
const speakCorrectedButton = document.querySelector("#speakCorrectedButton");
const speakTranslationButton = document.querySelector("#speakTranslationButton");
const saveButton = document.querySelector("#saveButton");
const deleteButton = document.querySelector("#deleteButton");
const prevMonth = document.querySelector("#prevMonth");
const nextMonth = document.querySelector("#nextMonth");
const todayButton = document.querySelector("#todayButton");
const refreshReviewButton = document.querySelector("#refreshReviewButton");

const defaultTopic = "\u65e5\u8a18\u306b\u56f0\u3063\u305f\u3089\u3001\u304a\u984c\u3092\u3082\u3089\u3048\u307e\u3059\u3002";
const storageKey = "english-diary-calendar:entries";
const settingsKey = "english-diary-calendar:settings";

const levelLabels = {
  toeic400: "TOEIC 400",
  toeic600: "TOEIC 600",
  toeic800: "TOEIC 800",
  natural: "\u81ea\u7136\u306a\u82f1\u8a9e"
};

const dateFormatter = new Intl.DateTimeFormat("ja-JP", {
  year: "numeric",
  month: "long",
  day: "numeric",
  weekday: "short"
});

const monthFormatter = new Intl.DateTimeFormat("en-US", {
  year: "numeric",
  month: "short"
});

let entries = {};
let visibleMonth = startOfMonth(new Date());
let selectedDate = toDateKey(new Date());

function startOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function toDateKey(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function fromDateKey(key) {
  const [year, month, day] = key.split("-").map(Number);
  return new Date(year, month - 1, day);
}

function addMonths(date, offset) {
  return new Date(date.getFullYear(), date.getMonth() + offset, 1);
}

function getDefaultTargetLevel() {
  const settings = JSON.parse(localStorage.getItem(settingsKey) || "{}");
  return settings.defaultTargetLevel || "toeic600";
}

function setStatus(message, isError = false) {
  statusEl.textContent = message;
  statusEl.style.color = isError ? "#c44536" : "";
}

async function fetchJson(url, options) {
  const response = await fetch(url, {
    headers: { "Content-Type": "application/json" },
    ...options
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(data.error || "Request failed.");
  }
  return data;
}

async function loadEntries() {
  entries = JSON.parse(localStorage.getItem(storageKey) || "{}");
  render();
}

function persistEntries() {
  localStorage.setItem(storageKey, JSON.stringify(entries));
}

function currentFormEntry() {
  return {
    japanese: japaneseInput.value.trim(),
    userEnglish: userEnglishInput.value.trim(),
    targetLevel: targetLevelSelect.value,
    correctedEnglish: correctedInput.value.trim(),
    translation: translationInput.value.trim(),
    topic: topicText.dataset.topic || "",
    correctionNotes: readList(correctionList),
    notes: readList(notesList),
    vocabulary: [...vocabList.querySelectorAll(".vocab-item")].map((item) => ({
      term: item.querySelector("strong")?.textContent.trim() || "",
      meaning: item.querySelector("[data-meaning]")?.textContent.trim() || "",
      example: item.querySelector("[data-example]")?.textContent.trim() || ""
    })),
    shadowingLines: readList(shadowingList),
    alternatives: [...alternativesList.querySelectorAll(".alternative-item")].map((item) => ({
      label: item.querySelector("strong")?.textContent.trim() || "",
      text: item.querySelector("p")?.textContent.trim() || ""
    }))
  };
}

function readList(list) {
  return [...list.querySelectorAll("li")].map((li) => li.textContent.trim());
}

function fillForm(entry = {}) {
  japaneseInput.value = entry.japanese || "";
  userEnglishInput.value = entry.userEnglish || "";
  targetLevelSelect.value = entry.targetLevel || getDefaultTargetLevel();
  correctedInput.value = entry.correctedEnglish || "";
  translationInput.value = entry.translation || entry.level600 || entry.english || "";
  topicText.textContent = entry.topic || defaultTopic;
  topicText.dataset.topic = entry.topic || "";
  renderTextList(correctionList, entry.correctionNotes || []);
  renderTextList(notesList, entry.notes || []);
  renderVocabulary(entry.vocabulary || []);
  renderTextList(shadowingList, entry.shadowingLines || []);
  renderAlternatives(entry.alternatives || []);
}

function renderTextList(list, items) {
  list.innerHTML = "";
  for (const text of items) {
    const li = document.createElement("li");
    li.textContent = text;
    list.append(li);
  }
}

function renderVocabulary(items) {
  vocabList.innerHTML = "";
  for (const item of items) {
    const box = document.createElement("div");
    box.className = "vocab-item";

    const term = document.createElement("strong");
    term.textContent = item.term;

    const meaning = document.createElement("span");
    meaning.dataset.meaning = "true";
    meaning.textContent = item.meaning;

    const example = document.createElement("span");
    example.dataset.example = "true";
    example.textContent = item.example;

    box.append(term, meaning, example);
    vocabList.append(box);
  }
}

function renderAlternatives(items) {
  alternativesList.innerHTML = "";
  for (const item of items) {
    const box = document.createElement("div");
    box.className = "alternative-item";

    const label = document.createElement("strong");
    label.textContent = item.label;

    const text = document.createElement("p");
    text.textContent = item.text;

    box.append(label, text);
    alternativesList.append(box);
  }
}

function render() {
  renderCalendar();
  selectedDateLabel.textContent = dateFormatter.format(fromDateKey(selectedDate));
  fillForm(entries[selectedDate]);
  renderStudySidebar();
}

function renderCalendar() {
  calendarGrid.innerHTML = "";
  monthLabel.textContent = monthFormatter.format(visibleMonth);
  renderMiniMonth(visibleMonth);
}

function renderMiniMonth(monthDate) {
  const section = document.createElement("section");
  section.className = "mini-month";

  const weekdays = document.createElement("div");
  weekdays.className = "mini-weekdays";
  for (const dayName of ["S", "M", "T", "W", "T", "F", "S"]) {
    const span = document.createElement("span");
    span.textContent = dayName;
    weekdays.append(span);
  }

  const grid = document.createElement("div");
  grid.className = "mini-grid";

  const first = startOfMonth(monthDate);
  const gridStart = new Date(first);
  gridStart.setDate(first.getDate() - first.getDay());

  for (let index = 0; index < 42; index += 1) {
    const day = new Date(gridStart);
    day.setDate(gridStart.getDate() + index);
    const key = toDateKey(day);
    const entry = entries[key];

    const button = document.createElement("button");
    button.className = "day-cell";
    button.type = "button";
    button.dataset.date = key;
    button.textContent = String(day.getDate());
    if (day.getMonth() !== monthDate.getMonth()) button.classList.add("is-muted");
    if (key === selectedDate) button.classList.add("is-selected");
    if (entry?.japanese) button.classList.add("has-entry");
    button.addEventListener("click", () => {
      selectedDate = key;
      render();
      setStatus("");
    });

    grid.append(button);
  }

  section.append(weekdays, grid);
  calendarGrid.append(section);
}

function allEntries() {
  return Object.values(entries).filter((entry) => entry?.japanese);
}

function renderStudySidebar() {
  renderReviewCards();
}

function appendChatMessage(role, text) {
  const item = document.createElement("div");
  item.className = `chat-message ${role}`;
  item.textContent = text;
  chatLog.append(item);
  chatLog.scrollTop = chatLog.scrollHeight;
}

function renderReviewCards() {
  const cards = allEntries()
    .flatMap((entry) => entry.vocabulary || [])
    .filter((item) => item.term && item.meaning)
    .slice(-12)
    .sort(() => Math.random() - 0.5)
    .slice(0, 3);

  reviewList.innerHTML = "";
  if (!cards.length) {
    reviewList.textContent = "\u65e5\u8a18\u3092\u4f5c\u308b\u3068\u5fa9\u7fd2\u30ab\u30fc\u30c9\u304c\u51fa\u307e\u3059\u3002";
    return;
  }

  for (const item of cards) {
    const card = document.createElement("div");
    card.className = "review-card";
    card.innerHTML = `<strong>${escapeHtml(item.term)}</strong><span>${escapeHtml(item.meaning)}</span><small>${escapeHtml(item.example || "")}</small>`;
    reviewList.append(card);
  }
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

async function saveSelected() {
  const payload = currentFormEntry();
  entries[selectedDate] = {
    date: selectedDate,
    ...payload,
    updatedAt: new Date().toISOString()
  };
  persistEntries();
  renderCalendar();
  renderStudySidebar();
  setStatus("\u4fdd\u5b58\u3057\u307e\u3057\u305f\u3002");
}

async function suggestTopic() {
  topicButton.disabled = true;
  setStatus("\u304a\u984c\u3092\u8003\u3048\u3066\u3044\u307e\u3059...");
  try {
    const result = await fetchJson("/api/topic", {
      method: "POST",
      body: JSON.stringify({ date: selectedDate })
    });
    recordUsage("topic");
    topicText.textContent = result.topic;
    topicText.dataset.topic = result.topic;
    setStatus("\u304a\u984c\u3092\u51fa\u3057\u307e\u3057\u305f\u3002");
  } catch (error) {
    setStatus(error.message, true);
  } finally {
    topicButton.disabled = false;
  }
}

async function translateSelected() {
  const japanese = japaneseInput.value.trim();
  if (!japanese) {
    setStatus("\u65e5\u672c\u8a9e\u306e\u65e5\u8a18\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002", true);
    return;
  }

  translateButton.disabled = true;
  setStatus("\u6dfb\u524a\u3068\u7ffb\u8a33\u4e2d...");
  try {
    const result = await fetchJson("/api/coach", {
      method: "POST",
      body: JSON.stringify({
        japanese,
        userEnglish: userEnglishInput.value.trim(),
        targetLevel: targetLevelSelect.value
      })
    });
    recordUsage("coach");
    correctedInput.value = result.correctedEnglish || "";
    translationInput.value = result.translation || "";
    renderTextList(correctionList, result.correctionNotes || []);
    renderTextList(notesList, result.notes || []);
    renderVocabulary(result.vocabulary || []);
    renderTextList(shadowingList, result.shadowingLines || []);
    renderAlternatives(result.alternatives || []);

    const payload = currentFormEntry();
    entries[selectedDate] = {
      date: selectedDate,
      ...payload,
      updatedAt: new Date().toISOString()
    };
    persistEntries();
    renderCalendar();
    renderStudySidebar();
    setStatus("\u6dfb\u524a\u3068\u7ffb\u8a33\u3092\u4fdd\u5b58\u3057\u307e\u3057\u305f\u3002");
  } catch (error) {
    setStatus(error.message, true);
  } finally {
    translateButton.disabled = false;
  }
}

async function deleteSelected() {
  delete entries[selectedDate];
  persistEntries();
  fillForm();
  renderCalendar();
  renderStudySidebar();
  setStatus("\u524a\u9664\u3057\u307e\u3057\u305f\u3002");
}

function speakText(text) {
  if (!text || !("speechSynthesis" in window)) return;
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = "en-US";
  utterance.rate = 0.9;
  window.speechSynthesis.cancel();
  window.speechSynthesis.speak(utterance);
}

async function askCoachQuestion() {
  const question = chatInput.value.trim();
  if (!question) return;

  appendChatMessage("user", question);
  chatInput.value = "";
  chatSendButton.disabled = true;

  try {
    const result = await fetchJson("/api/chat", {
      method: "POST",
      body: JSON.stringify({
        question,
        context: {
          japanese: japaneseInput.value.trim(),
          userEnglish: userEnglishInput.value.trim(),
          correctedEnglish: correctedInput.value.trim(),
          translation: translationInput.value.trim(),
          targetLevel: targetLevelSelect.value,
          correctionNotes: readList(correctionList),
          notes: readList(notesList),
          vocabulary: [...vocabList.querySelectorAll(".vocab-item")].map((item) => ({
            term: item.querySelector("strong")?.textContent.trim() || "",
            meaning: item.querySelector("[data-meaning]")?.textContent.trim() || "",
            example: item.querySelector("[data-example]")?.textContent.trim() || ""
          }))
        }
      })
    });
    recordUsage("chat");
    appendChatMessage("assistant", result.answer || "");
  } catch (error) {
    appendChatMessage("assistant", error.message);
  } finally {
    chatSendButton.disabled = false;
  }
}

prevMonth.addEventListener("click", () => {
  visibleMonth = addMonths(visibleMonth, -1);
  renderCalendar();
});

nextMonth.addEventListener("click", () => {
  visibleMonth = addMonths(visibleMonth, 1);
  renderCalendar();
});

todayButton.addEventListener("click", () => {
  const today = new Date();
  selectedDate = toDateKey(today);
  visibleMonth = startOfMonth(today);
  render();
});

topicButton.addEventListener("click", suggestTopic);
speakCorrectedButton.addEventListener("click", () => {
  speakText(correctedInput.value.trim());
});
speakTranslationButton.addEventListener("click", () => {
  speakText(translationInput.value.trim());
});
refreshReviewButton.addEventListener("click", renderReviewCards);
chatSendButton.addEventListener("click", askCoachQuestion);
chatInput.addEventListener("keydown", (event) => {
  if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) {
    askCoachQuestion();
  }
});

saveButton.addEventListener("click", () => {
  saveSelected().catch((error) => setStatus(error.message, true));
});

translateButton.addEventListener("click", translateSelected);

deleteButton.addEventListener("click", () => {
  deleteSelected().catch((error) => setStatus(error.message, true));
});

loadEntries().catch((error) => setStatus(error.message, true));
