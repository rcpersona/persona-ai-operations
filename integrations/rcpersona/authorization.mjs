import { createHash, randomBytes } from "node:crypto";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { setTimeout as delay } from "node:timers/promises";
import { siteOrigin } from "./client.mjs";

export const productionSite = "https://rcpersona.com";

async function openBrowser(url) {
  if (process.platform !== "win32") throw new Error("Automatic browser launch requires Windows.");
  await promisify(execFile)("rundll32.exe", ["url.dll,FileProtocolHandler", url.href],
    { timeout: 10000, windowsHide: true });
}

export function normalizePassword(password) {
  const value = typeof password === "string" ? password.replace(/\s/g, "") : "";
  if (!/^[A-Za-z0-9]{24}$/.test(value)) throw new Error("Enter a 24-character WordPress Application Password. Spaces are allowed.");
  return value;
}

export async function browserCredentials(site, {
  fetcher = fetch, launch = openBrowser, sleep = delay, verifier = randomBytes(32).toString("hex"),
} = {}) {
  const origin = siteOrigin(site);
  const post = async (path, body) => {
    try {
      return await fetcher(new URL("/wp-json/persona-ai/v1/connection/" + path, origin), {
        method: "POST", redirect: "error", cache: "no-store", signal: AbortSignal.timeout(10000),
        headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(body),
      });
    } catch {
      throw new Error("Could not reach the RCPersona authorization endpoint over trusted HTTPS.");
    }
  };
  const initial = await post("start", { challenge: createHash("sha256").update(verifier).digest("hex") });
  if (!initial.ok) {
    await initial.body?.cancel();
    throw new Error("Browser authorization is unavailable on this RCPersona site.");
  }
  const challenge = await initial.json().catch(() => null);
  let authorization;
  try { authorization = new URL(challenge.authorization_url); } catch {
    throw new Error("RCPersona returned an invalid authorization URL.");
  }
  if (authorization.origin !== origin || authorization.username || authorization.password ||
      authorization.hash || authorization.pathname !== "/wp-admin/admin-post.php" ||
      authorization.searchParams.get("action") !== "rcp_ai_authorize" ||
      !/^\d{10}\.[a-f0-9]{64}\.[a-f0-9]{64}$/.test(challenge.state) ||
      authorization.searchParams.get("state") !== challenge.state ||
      [...authorization.searchParams.keys()].length !== 2 ||
      !Number.isInteger(challenge.expires_in) || challenge.expires_in < 1 || challenge.expires_in > 300) {
    throw new Error("RCPersona returned an invalid authorization session.");
  }
  try { await launch(authorization); } catch {
    throw new Error("Could not open the browser. Start the connection again from Codex.");
  }
  const expiry = Date.now() + challenge.expires_in * 1000;
  while (Date.now() < expiry) {
    const response = await post("redeem", { state: challenge.state, verifier });
    if (response.status === 202) {
      await response.body?.cancel();
      await sleep(2500);
      continue;
    }
    if (!response.ok) {
      await response.body?.cancel();
      if (response.status === 403) throw new Error("Connection declined or the WordPress profile has no access.");
      if ([400, 409].includes(response.status)) throw new Error("The authorization session expired or was already used. Start again.");
      throw new Error("RCPersona could not complete the authorization.");
    }
    const data = await response.json().catch(() => null);
    if (typeof data?.login !== "string" || !data.login || data.login.includes(":")) {
      throw new Error("RCPersona returned an invalid account.");
    }
    return { site: origin, login: data.login, password: normalizePassword(data.password) };
  }
  throw new Error("Browser authorization expired. Start again and confirm within five minutes.");
}
