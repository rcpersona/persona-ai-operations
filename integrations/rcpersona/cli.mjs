import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";
import { z } from "zod";
import { browserCredentials, normalizePassword, productionSite } from "./authorization.mjs";
import { loadCredentials, callTool, siteOrigin, whoami } from "./client.mjs";
import { tools } from "./tools.mjs";

const credentialScript = fileURLToPath(new URL("./credentials.ps1", import.meta.url));

async function vault(action, payload) {
  if (process.platform !== "win32") throw new Error("Windows DPAPI is required.");
  await new Promise((resolve, reject) => {
    const child = spawn("powershell.exe", ["-NoProfile", "-NonInteractive", "-File", credentialScript, action],
      { windowsHide: true, stdio: ["pipe", "ignore", "ignore"] });
    const timeout = setTimeout(() => child.kill(), 10000);
    child.stdin.on("error", () => {});
    child.once("error", () => reject(new Error("Could not open the Windows credential vault.")));
    child.once("close", (code) => {
      clearTimeout(timeout);
      if (code === 0) resolve();
      else reject(new Error("Could not update the Windows credential vault."));
    });
    child.stdin.end(payload ? JSON.stringify(payload) : undefined, "utf8");
  });
}

async function readInput() {
  let input = "";
  process.stdin.setEncoding("utf8");
  for await (const chunk of process.stdin) {
    input += chunk;
    if (input.length > 8192) throw new Error("Connection input is too large.");
  }
  try { return JSON.parse(input); } catch { throw new Error("Invalid connection input."); }
}

try {
  const [action = "status", ...args] = process.argv.slice(2);
  if (action === "disconnect") {
    await vault("disconnect");
    console.log("Disconnected locally. To revoke the Application Password, remove it in your WordPress profile.");
  } else if (action === "tool") {
    const [name, input = "{}"] = args;
    if (!Object.hasOwn(tools, name) || args.length > 2) throw new Error("Unknown RCPersona read tool.");
    const [, schema, route] = tools[name];
    let parameters;
    try { parameters = z.strictObject(schema).parse(JSON.parse(input)); } catch {
      throw new Error("Invalid RCPersona read tool parameters.");
    }
    const credentials = await loadCredentials();
    const result = name === "persona_whoami" ? await whoami(credentials) : await callTool(credentials, route(parameters), parameters);
    console.log(JSON.stringify(result));
  } else {
    let profile;
    if (action === "connect") {
      const developer = args.includes("--dev");
      const siteArg = args.find(arg => arg.startsWith("--site="));
      if (args.some(arg => arg !== "--dev" && arg !== siteArg) || (siteArg && !developer)) {
        throw new Error("Custom website URLs are only available with --dev.");
      }
      const credentials = await browserCredentials(developer ? siteOrigin(siteArg?.slice(7) ?? "https://persona") : productionSite);
      profile = await whoami(credentials);
      await vault("write", credentials);
    } else if (action === "verify") {
      const credentials = await readInput();
      credentials.password = normalizePassword(credentials.password);
      profile = await whoami(credentials);
    } else if (action === "status") {
      profile = await whoami(await loadCredentials());
    } else {
      throw new Error("Use connect, status, or disconnect.");
    }
    console.log(JSON.stringify(profile));
  }
} catch (error) {
  process.stderr.write(error.message + "\n");
  process.exitCode = 1;
}
