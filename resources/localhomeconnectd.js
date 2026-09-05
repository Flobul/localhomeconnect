#!/usr/bin/env node
"use strict";

const daemon = require("./node/daemon");

daemon.main().catch(error => {
  daemon.logMessage("error", error instanceof Error ? error.message : String(error));
  process.exit(1);
});
