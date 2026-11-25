import { execFile } from "node:child_process";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";

const credentialScript = fileURLToPath(new URL("./credentials.ps1", import.meta.url));
const maxBytes = 256 * 1024;

export function siteOrigin(value) {
  const url = new URL(value);
  if (url.protocol !== "https:" || url.username || url.password || url.pathname !== "/" || url.search || url.hash) {
    throw new Error("Enter the HTTPS website origin without a path, login, or query.");
  }
  return url.origin;
}

export async function loadCredentials() {
  if (process.platform !== "win32") throw new Error("Windows DPAPI is required.");
  let result;
  try {
    result = await promisify(execFile)("powershell.exe",
      ["-NoProfile", "-NonInteractive", "-File", credentialScript, "read"],
      { windowsHide: true, timeout: 8000, maxBuffer: 8192, encoding: "utf8" });
  } catch {
    throw new Error("Local credentials are unavailable. Ask Codex to connect to RCPersona again.");
  }
  try {
    const { site, login, password } = JSON.parse(result.stdout);
    return { site: siteOrigin(site), login, password };
  } catch {
    throw new Error("Local credentials are invalid. Run connect.bat again.");
  }
}

const statuses = {
  400: "RCPersona rejected the query.",
  401: "RCPersona rejected the Application Password or its connector designation.",
  403: "RCPersona denied access. Check the profile and current permissions.",
  404: "The endpoint or accessible record was not found.",
  422: "The requested period contains too many records. Use a shorter period.",
  429: "RCPersona rate limit reached. Retry in the next minute.",
  503: "RCPersona could not read the requested data.",
};

export async function request(credentials, path, args = {}, fetcher = fetch) {
  const url = new URL("/wp-json/persona-ai/v1/" + path, siteOrigin(credentials.site));
  for (const [key, value] of Object.entries(args)) {
    if (key !== "id" && value !== undefined) url.searchParams.set(key, String(value));
  }
  const authorization = Buffer.from(`${credentials.login}:${credentials.password}`, "utf8").toString("base64");
  let response;
  try {
    response = await fetcher(url, {
      method: "GET",
      redirect: "error",
      signal: AbortSignal.timeout(15000),
      headers: { Authorization: "Basic " + authorization, Accept: "application/json", "Cache-Control": "no-store" },
    });
  } catch {
    throw new Error("RCPersona HTTPS request failed. Check the origin, network, and certificate.");
  }
  if (!response.ok) {
    await response.body?.cancel();
    if (response.status === 503 && args.peopleforce) {
      throw new Error("PeopleForce linkage could not be read from RCPersona.");
    }
    throw new Error(statuses[response.status] ?? `RCPersona returned HTTP ${response.status}.`);
  }
  if (!response.headers.get("content-type")?.includes("application/json") || !response.body) {
    await response.body?.cancel();
    throw new Error("RCPersona did not return JSON.");
  }
  const reader = response.body.getReader();
  const chunks = [];
  let size = 0;
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.length;
      if (size > maxBytes) throw new Error();
      chunks.push(value);
    }
    const data = JSON.parse(Buffer.concat(chunks).toString("utf8"));
    if (!data || typeof data !== "object" || Array.isArray(data)) throw new Error();
    return data;
  } catch {
    await reader.cancel().catch(() => {});
    throw new Error("RCPersona returned an invalid, incomplete, or oversized response.");
  }
}

export async function whoami(credentials) {
  const profile = await request(credentials, "me");
  if (profile.connector !== "persona-ai/v1" || profile.active !== true ||
      !Number.isSafeInteger(profile.user_id) || profile.user_id < 1 ||
      typeof profile.permissions?.read_candidates !== "boolean" ||
      typeof profile.permissions?.read_vacancies !== "boolean") {
    throw new Error("RCPersona did not confirm an active profile and connector permissions.");
  }
  return {
    connector: profile.connector,
    user_id: profile.user_id,
    active: true,
    display_name: typeof profile.display_name === "string" ? profile.display_name.slice(0, 200) : "",
    roles: Array.isArray(profile.roles) ? profile.roles.filter(role => typeof role === "string").slice(0, 20) : [],
    access_mode: "read_only",
    permissions: {
      read_candidates: profile.permissions.read_candidates,
      read_vacancies: profile.permissions.read_vacancies,
    },
  };
}
