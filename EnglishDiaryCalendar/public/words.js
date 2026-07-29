const wordForm = document.querySelector("#wordForm");
const wordEnglish = document.querySelector("#wordEnglish");
const wordJapanese = document.querySelector("#wordJapanese");
const wordToeic = document.querySelector("#wordToeic");
const wordList = document.querySelector("#wordList");

const weakWordsKey = "english-diary-calendar:weakWords";

function readWords() {
  return JSON.parse(localStorage.getItem(weakWordsKey) || "[]");
}

function writeWords(words) {
  localStorage.setItem(weakWordsKey, JSON.stringify(words));
}

function renderWords() {
  const words = readWords();
  wordList.innerHTML = "";

  if (!words.length) {
    wordList.textContent = "まだ登録されていません。";
    return;
  }

  for (const word of words) {
    const row = document.createElement("div");
    row.className = "word-row";
    row.innerHTML = `
      <div>
        <strong>${escapeHtml(word.english)}</strong>
        <span>${escapeHtml(word.japanese)}</span>
      </div>
      <div class="word-meta">TOEIC ${escapeHtml(word.toeic)}</div>
      <button class="small-button" data-delete="${word.id}">Delete</button>
    `;
    wordList.append(row);
  }
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

wordForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const words = readWords();
  words.unshift({
    id: crypto.randomUUID(),
    english: wordEnglish.value.trim(),
    japanese: wordJapanese.value.trim(),
    toeic: wordToeic.value,
    createdAt: new Date().toISOString()
  });
  writeWords(words);
  wordForm.reset();
  wordToeic.value = "600";
  renderWords();
});

wordList.addEventListener("click", (event) => {
  const id = event.target?.dataset?.delete;
  if (!id) return;
  writeWords(readWords().filter((word) => word.id !== id));
  renderWords();
});

renderWords();
