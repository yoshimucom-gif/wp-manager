import { currentMonthKey, estimateCost, readUsage, usageRates } from "./usage-tracker.js";

const monthlyCost = document.querySelector("#monthlyCost");
const costRange = document.querySelector("#costRange");
const dailyCosts = document.querySelector("#dailyCosts");
const usageStatus = document.querySelector("#usageStatus");
const refreshUsageButton = document.querySelector("#refreshUsageButton");

function formatMoney(value) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 4
  }).format(value);
}

function renderUsage() {
  const month = currentMonthKey();
  const counts = readUsage()[month] || { coach: 0, topic: 0, chat: 0 };
  const total = estimateCost(counts);

  monthlyCost.textContent = formatMoney(total);
  costRange.textContent = `${month} / usage-based estimate`;
  usageStatus.textContent =
    "実際の請求額ではなく、このアプリ内で使った回数から計算した目安です。";

  const rows = [
    ["添削と翻訳", "coach", usageRates.coach],
    ["お題生成", "topic", usageRates.topic],
    ["なぜ？質問", "chat", usageRates.chat]
  ];

  dailyCosts.innerHTML = "";
  for (const [label, type, rate] of rows) {
    const count = counts[type] || 0;
    const row = document.createElement("div");
    row.className = "daily-cost-row";
    row.innerHTML = `<span>${label}<small>${count}回 x ${formatMoney(rate)}</small></span><strong>${formatMoney(count * rate)}</strong>`;
    dailyCosts.append(row);
  }
}

refreshUsageButton.addEventListener("click", renderUsage);
renderUsage();
