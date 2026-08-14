const { spawnSync } = require('child_process');
const path = require('path');

const checkpoints = [
  'first_checkpoint.js',
  'remote_endpoint_checkpoint.js',
  'panel_link_ui_checkpoint.js',
  'socket_endpoint_checkpoint.js',
  'link_disconnect_checkpoint.js',
  'second_checkpoint.js',
  'route_explorer_checkpoint.js',
  'native_impact_checkpoint.js',
  'csv_import_checkpoint.js',
  'labels_checkpoint.js',
  'audit_checkpoint.js',
  'corrupt_data_checkpoint.js',
  'route_consistency_checkpoint.js',
  'database_model_checkpoint.js',
  'health_checkpoint.js',
  'accessibility_checkpoint.js',
];

const results = [];

for (const checkpoint of checkpoints) {
  const startedAt = Date.now();
  const result = spawnSync(process.execPath, [path.join(__dirname, checkpoint)], {
    encoding: 'utf8',
    env: process.env,
    stdio: ['inherit', 'pipe', 'pipe'],
  });

  if (result.stdout) {
    process.stdout.write(result.stdout);
  }
  if (result.stderr) {
    process.stderr.write(result.stderr);
  }

  results.push({
    checkpoint,
    status: result.status === 0 ? 'passed' : 'failed',
    exit_code: result.status,
    duration_ms: Date.now() - startedAt,
  });
}

const failed = results.filter(result => result.status === 'failed');
console.log(JSON.stringify({
  passed: results.length - failed.length,
  failed: failed.length,
  results,
}, null, 2));

if (failed.length) {
  process.exitCode = 1;
}
