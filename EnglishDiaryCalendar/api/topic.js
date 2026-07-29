const OPENAI_MODEL = process.env.OPENAI_MODEL || "gpt-5.2";

function extractOutputText(data) {
  if (data.output_text) return data.output_text;

  for (const item of data.output || []) {
    for (const content of item.content || []) {
      if (typeof content.text === "string") {
        return content.text;
      }
    }
  }

  return "";
}

export default async function handler(req, res) {
  if (req.method !== "POST") {
    res.setHeader("Allow", "POST");
    res.status(405).json({ error: "Method not allowed." });
    return;
  }

  if (!process.env.OPENAI_API_KEY) {
    res.status(500).json({ error: "OPENAI_API_KEY is not set." });
    return;
  }

  try {
    const response = await fetch("https://api.openai.com/v1/responses", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        model: OPENAI_MODEL,
        input: [
          {
            role: "system",
            content:
              "You suggest short diary prompts for a Japanese learner of English. Return JSON only."
          },
          {
            role: "user",
            content:
              "今日の英語日記のお題を1つください。日常的で書きやすく、1-2文で答えられるものにしてください。日本語で返してください。"
          }
        ],
        text: {
          format: {
            type: "json_schema",
            name: "diary_topic",
            schema: {
              type: "object",
              additionalProperties: false,
              properties: {
                topic: { type: "string" }
              },
              required: ["topic"]
            }
          }
        }
      })
    });

    if (!response.ok) {
      const detail = await response.text();
      res.status(response.status).json({ error: `OpenAI API error: ${detail}` });
      return;
    }

    const data = await response.json();
    const outputText = extractOutputText(data);
    if (!outputText) {
      res.status(500).json({ error: "OpenAI API returned no output_text." });
      return;
    }

    res.status(200).json(JSON.parse(outputText));
  } catch (error) {
    res.status(500).json({ error: error.message || "Server error." });
  }
}
