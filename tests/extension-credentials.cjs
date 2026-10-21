// Exercise the credential handoff without creating real API credentials.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const button = {addEventListener: (_, handler) => { button.click = handler; }};
const status = {};
const frame = {hidden: true, removeAttribute: key => { delete frame[key]; }};
const elements = {'#extension-connect': button, '#extension-status': status, '#extension-credentials': frame};
const requests = [];
let receive;
const parent = {postMessage: request => requests.push(request.renderTemplate)};
vm.runInNewContext(fs.readFileSync('integration/sandstorm-extensions.js', 'utf8'), {
    document: {querySelector: selector => elements[selector]},
    window: {parent, addEventListener: (_, handler) => { receive = handler; }},
    location: {protocol: 'https:'}, URL,
});
button.click();
const request = requests[0];
assert.equal(request.roleAssignment.roleId, 0, 'Extension needs Collaborator access');
assert.equal(request.template, 'https://$API_HOST/#$API_TOKEN');
assert.equal(button.disabled, true);
receive({source: {}, data: {rpcId: request.rpcId, uri: 'https://attacker.invalid'}});
assert.equal(frame.src, undefined, 'Ignore non-parent messages');
receive({source: parent, data: {rpcId: 'wrong', uri: 'https://attacker.invalid'}});
assert.equal(frame.src, undefined, 'Ignore unrelated replies');
receive({source: parent, data: {rpcId: request.rpcId, uri: 'https://shell.invalid/offer'}});
assert.equal(frame.src, 'https://shell.invalid/offer');
assert.equal(frame.hidden, false);
assert.equal(button.disabled, false);
for (const uri of ['javascript:alert(1)', 'https://user:password@shell.invalid', undefined]) {
    button.click();
    assert.equal(frame.hidden, true);
    receive({source: parent, data: {rpcId: requests.at(-1).rpcId, uri}});
    assert.equal(frame.src, undefined);
    assert.equal(button.disabled, false);
    assert.match(status.textContent, /invalid/);
}
button.click();
receive({source: parent, data: {rpcId: requests.at(-1).rpcId, error: 'Permission denied'}});
assert.equal(status.textContent, 'Permission denied');
assert.equal(button.disabled, false);
console.log('PASS: credential role and webkey format, message source/correlation, invalid URLs, error recovery');
