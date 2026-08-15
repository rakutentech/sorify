const dot = document.getElementById('dot')
const statusText = document.getElementById('status-text')
const connectBtn = document.getElementById('connect-btn')
const recordBtn = document.getElementById('record-btn')
const eventCount = document.getElementById('event-count')
const configBlock = document.getElementById('config')
const configJson = document.getElementById('config-json')

const MCP_CONFIG = {
    'sorify-recorder': {
        command: '~/.sorify-bin/sorify-recorder-mcp'
    }
}

function render (status) {
    const connected = !!status.connected
    const recording = !!status.active_session_id

    dot.classList.toggle('on', connected)
    statusText.textContent = connected
        ? recording
            ? 'Connected — recording'
            : 'Connected'
        : 'Not connected'

    connectBtn.textContent = connected ? 'Disconnect' : 'Connect'
    recordBtn.disabled = !connected
    recordBtn.textContent = recording ? 'Stop recording' : 'Start recording'

    eventCount.textContent = recording
        ? `${status.event_count ?? 0} events captured`
        : ''

    if (connected) {
        configJson.textContent = JSON.stringify(MCP_CONFIG, null, 2)
        configBlock.style.display = 'block'
    } else {
        configBlock.style.display = 'none'
    }
}

function refresh () {
    chrome.runtime.sendMessage({ action: 'get_status' }, status =>
        render(status || {})
    )
}

connectBtn.addEventListener('click', () => {
    const action =
        connectBtn.textContent === 'Connect' ? 'connect' : 'disconnect'
    chrome.runtime.sendMessage({ action }, () => setTimeout(refresh, 300))
})

recordBtn.addEventListener('click', () => {
    const action =
        recordBtn.textContent === 'Start recording'
            ? 'start_recording'
            : 'stop_recording'
    chrome.runtime.sendMessage({ action, label: 'manual' }, () =>
        setTimeout(refresh, 300)
    )
})

chrome.runtime.onMessage.addListener(message => {
    if (message.action === 'status_update') render(message.status)
})

refresh()
