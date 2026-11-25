import assert from "node:assert/strict";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";
import { callTool, request, siteOrigin } from "./client.mjs";
import { tools } from "./tools.mjs";

const synthetic = { site: "https://fixture.invalid", login: "fixture", password: "synthetic-password" };

test("HTTPS origin validation", () => {
  assert.equal(siteOrigin("https://persona/"), "https://persona");
  for (const url of ["http://persona/", "https://u@persona/", "https://persona/path", "https://persona/?x=1"]) {
    assert.throws(() => siteOrigin(url));
  }
});

test("API requests use GET and keep private search terms out of URLs", async () => {
  const data = await request(synthetic, "candidates", { q: "C++ & PLC", email: "sample@fixture.invalid", limit: 5 }, async (url, opts) => {
    assert.equal(url.origin, synthetic.site);
    assert.equal(url.pathname, "/wp-json/persona-ai/v1/candidates");
    assert.equal(url.search, "?limit=5");
    assert.deepEqual(JSON.parse(Buffer.from(opts.headers["X-RCPersona-Search"], "base64")),
      { q: "C++ & PLC", email: "sample@fixture.invalid" });
    assert.equal(opts.method, "GET");
    assert.equal(opts.redirect, "error");
    assert.equal(opts.headers.Authorization, "Basic " + Buffer.from("fixture:synthetic-password").toString("base64"));
    return Response.json({ items: [] });
  });
  assert.deepEqual(data, { items: [] });
});

test("authentication errors and oversized responses do not leak response contents", async () => {
  for (const status of [401, 403, 429]) {
    await assert.rejects(request(synthetic, "me", {}, async () => Response.json({ secret: "hidden" }, { status })),
      error => !error.message.includes("hidden"));
  }
  await assert.rejects(request(synthetic, "me", {}, async () => Response.json({ field: "x".repeat(270000) })),
    /oversized/);
});

test("stdio MCP advertises all recruiting tools and validates integer identifiers", async () => {
  const client = new Client({ name: "rcpersona-test", version: "1" });
  try {
    await client.connect(new StdioClientTransport({
      command: process.execPath,
      args: [fileURLToPath(new URL("./mcp.mjs", import.meta.url))],
    }));
    const result = await client.listTools();
    assert.equal(result.tools.length, 16);
    assert.deepEqual(result.tools.map(tool => tool.name).sort(), Object.keys(tools).sort());
    assert.ok(result.tools.every(tool => tool.annotations?.readOnlyHint));
    const invalid = await client.callTool({ name: "persona_get_candidate", arguments: { id: "99/other" } });
    assert.equal(invalid.isError, true);
    assert.match(invalid.content[0].text, /invalid.*arguments|input validation/i);
  } finally {
    await client.close();
  }
});

test("verify component compatibility before a data query using current server capabilities", async () => {
  const paths = [];
  const data = await callTool(synthetic, "candidates/42", { id: 42, include_contacts: true }, async url => {
    paths.push(url.pathname);
    return url.pathname.endsWith("/capabilities")
      ? Response.json({ api_version: 1, component_version: "1.1.0", entities: { candidates: {} } })
      : Response.json({ id: 42, contacts: { email: "sample@fixture.invalid" } });
  });
  assert.deepEqual(paths, ["/wp-json/persona-ai/v1/capabilities", "/wp-json/persona-ai/v1/candidates/42"]);
  assert.equal(data.id, 42);
  for (const version of ["1.0.9", "2.0.0", "unknown"]) {
    await assert.rejects(callTool(synthetic, "candidates", {}, async url => {
      assert.ok(url.pathname.endsWith("/capabilities"));
      return Response.json({ api_version: 1, component_version: version, entities: {} });
    }), /Incompatible/);
  }
  await assert.rejects(callTool(synthetic, "candidates", {}, async () => Response.json({}, { status: 404 })), /Update the RCPersona WordPress component/);
});

test("browser consent sends only the challenge to start and keeps password out of the URL", async () => {
  const { browserCredentials } = await import("./authorization.mjs");
  const verifier = "b".repeat(64);
  const token = "1234567890." + "a".repeat(64) + "." + "c".repeat(64);
  let requests = 0;
  let opened = false;
  const result = await browserCredentials(synthetic.site, {
    verifier,
    launch: async url => {
      opened = true;
      assert.equal(url.searchParams.get("state"), token);
      assert.equal(url.href.includes(verifier), false);
    },
    sleep: async () => {},
    fetcher: async (url, init) => {
      const body = JSON.parse(init.body);
      assert.equal(init.redirect, "error");
      assert.equal(url.search, "");
      requests++;
      if (url.pathname.endsWith("/start")) {
        assert.equal(Object.keys(body).join(), "challenge");
        assert.notEqual(body.challenge, verifier);
        return Response.json({ state: token, expires_in: 300,
          authorization_url: synthetic.site + "/wp-admin/admin-post.php?action=rcp_ai_authorize&state=" + token });
      }
      assert.ok(opened);
      assert.equal(body.verifier, verifier);
      return requests === 2 ? Response.json({ status: "pending" }, { status: 202 })
        : Response.json({ login: "fixture", password: "abcd efgh ijkl mnop qrst uvwx" });
    },
  });
  assert.equal(result.password, "abcdefghijklmnopqrstuvwx");
  assert.equal(requests, 3);
});

test("browser consent rejects foreign authorization URLs before launching", async () => {
  const { browserCredentials, normalizePassword } = await import("./authorization.mjs");
  assert.equal(normalizePassword("abcd\tefgh ijkl\nmnop\u00a0qrst uvwx"), "abcdefghijklmnopqrstuvwx");
  assert.throws(() => normalizePassword("wrong"));
  await assert.rejects(browserCredentials(synthetic.site, {
    launch: () => assert.fail("must not open a foreign URL"),
    fetcher: async () => Response.json({ authorization_url: "https://foreign.invalid/" }),
  }), /invalid authorization session/);
});
