chrome.runtime.onInstalled.addListener(() => chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }));
chrome.tabs.onActivated.addListener(() => chrome.runtime.sendMessage({ type: 'annotated:tab-changed' }).catch(() => {}));
chrome.tabs.onUpdated.addListener((_id, info) => { if (info.status === 'complete') chrome.runtime.sendMessage({ type: 'annotated:tab-changed' }).catch(() => {}); });
