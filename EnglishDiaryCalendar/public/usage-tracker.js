export const usageKey = "english-diary-calendar:usage";

export const usageRates = {
  coach: 0.006,
  topic: 0.001,
  chat: 0.002
};

export function currentMonthKey(date = new Date()) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

export function readUsage() {
  return JSON.parse(localStorage.getItem(usageKey) || "{}");
}

export function writeUsage(usage) {
  localStorage.setItem(usageKey, JSON.stringify(usage));
}

export function recordUsage(type) {
  const month = currentMonthKey();
  const usage = readUsage();
  usage[month] ||= { coach: 0, topic: 0, chat: 0 };
  usage[month][type] = (usage[month][type] || 0) + 1;
  writeUsage(usage);
}

export function estimateCost(counts) {
  return Object.entries(usageRates).reduce((total, [type, rate]) => {
    return total + (counts?.[type] || 0) * rate;
  }, 0);
}
