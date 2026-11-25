import { loadCredentials, whoami } from "./client.mjs";

try {
  let credentials;
  if (process.argv[2] === "verify") {
    process.stdin.setEncoding("utf8");
    let input = "";
    for await (const chunk of process.stdin) {
      input += chunk;
      if (input.length > 8192) throw new Error("Connection input is too large.");
    }
    credentials = JSON.parse(input);
  } else {
    credentials = await loadCredentials();
  }
  const profile = await whoami(credentials);
  process.stdout.write(JSON.stringify(profile) + "\n");
} catch (error) {
  process.stderr.write(error.message + "\n");
  process.exitCode = 1;
}
