@once
<style>
.vm-console {
    --vm-bg:#fff;
    --vm-muted:#778396;
    --vm-line:#e8edf3;
    --vm-text:#26364b;
    --vm-accent:#cf2843;
    color:var(--vm-text);
    font-size:14px;
    text-align:left;
}
.dark .vm-console {
    --vm-bg:#191f2b;
    --vm-muted:#94a3b8;
    --vm-line:#303948;
    --vm-text:#e2e8f0;
    --vm-accent:#f0647b;
}
.vm-console h2,.vm-console h3,.vm-console h4,.vm-console p {
    margin:0;
}
.vm-console h2 {
    font-size:23px;
    font-weight:650;
    line-height:1.5;
}
.vm-console h3 {
    font-size:16px;
    font-weight:650;
    margin-bottom:8px;
}
.vm-console h4 {
    font-weight:600;
}
.vm-console p,.vm-console small {
    color:var(--vm-muted);
    line-height:1.8;
}
.vm-console svg {
    width:18px;
    height:18px;
    flex-shrink:0;
}
.vm-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:28px;
    background:var(--vm-bg);
    border:1px solid var(--vm-line);
    border-radius:14px 14px 0 0;
}
.vm-heading {
    display:flex;
    gap:18px;
    align-items:center;
    min-width:0;
}
.vm-heading h2 {
    overflow-wrap:anywhere;
}
.vm-heading p span {
    padding:0 8px;
}
.vm-eyebrow {
    font-size:11px;
    font-weight:650;
    color:var(--vm-muted);
    letter-spacing:.8px;
}
.vm-server-icon {
    display:grid;
    place-items:center;
    width:56px;
    height:56px;
    border-radius:16px;
    background:#cf284312;
    color:var(--vm-accent);
    flex-shrink:0;
}
.vm-server-icon svg {
    width:28px;
    height:28px;
}
.vm-actions {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}
.vm-console button,.vm-console a {
    cursor:pointer;
}
.vm-console button {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid var(--vm-line);
    background:var(--vm-bg);
    border-radius:7px;
    padding:8px 12px;
    font:inherit;
    white-space:nowrap;
}
.vm-console button:hover {
    border-color:var(--vm-accent);
    color:var(--vm-accent);
}
.vm-console button:disabled {
    opacity:.45;
    cursor:not-allowed;
}
.vm-console button.vm-primary {
    color:white;
    background:var(--vm-accent);
    border-color:var(--vm-accent);
}
.vm-console .vm-danger {
    color:#d84e5d;
}
.vm-status {
    font-size:12px;
    display:inline-flex;
    gap:7px;
    align-items:center;
    background:#94a3b814;
    color:var(--vm-muted);
    padding:6px 11px;
    border-radius:30px;
}
.vm-status:before {
    content:'';
    width:6px;
    height:6px;
    background:currentColor;
    border-radius:50%;
}
.vm-status.is-running {
    color:#16a581;
    background:#16a58112;
}
.vm-toolbar {
    background:var(--vm-bg);
    border:1px solid var(--vm-line);
    border-top:0;
    border-radius:0 0 14px 14px;
    display:flex;
    justify-content:space-between;
    gap:15px;
    align-items:center;
    padding:16px 24px;
    margin-bottom:24px;
}
.vm-stats {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:18px;
    margin-bottom:22px;
}
.vm-stat,.vm-panel {
    background:var(--vm-bg);
    border:1px solid var(--vm-line);
    border-radius:4px;
    padding:18px;
}
.vm-stat>span {
    display:block;
    color:var(--vm-muted);
    font-size:12px;
}
.vm-stat strong {
    display:block;
    font-size:26px;
    letter-spacing:-.6px;
    font-weight:600;
    margin:10px 0 1px;
}
.vm-meter {
    height:5px;
    background:var(--vm-line);
    border-radius:10px;
    margin-top:18px;
    overflow:hidden;
}
.vm-meter i {
    display:block;
    height:100%;
    background:#cf2843;
    border-radius:10px;
}
.vm-columns {
    display:grid;
    grid-template-columns:1.2fr 1fr;
    gap:22px;
}
.vm-panel dl {
    margin:17px 0 0;
}
.vm-panel dl>div {
    display:flex;
    justify-content:space-between;
    gap:20px;
    border-bottom:1px solid var(--vm-line);
    padding:12px 0;
}
.vm-panel dl>div:last-child {
    border:0;
}
.vm-panel dt {
    color:var(--vm-muted);
    flex-shrink:0;
}
.vm-panel dd {
    text-align:right;
    overflow-wrap:anywhere;
    margin:0;
}
.vm-console-access {
    margin-top:12px;
    padding-top:12px;
    border-top:1px solid var(--vm-line);
}
.vm-console-access p {
    font-size:12px;
    margin:8px 0 14px;
}
.vm-console-credentials a {
    color:var(--vm-accent);
}
.vm-console label {
    font-size:12px;
    display:block;
    color:var(--vm-muted);
}
.vm-console input {
    display:block;
    border:1px solid var(--vm-line);
    border-radius:7px;
    background:var(--vm-bg);
    color:var(--vm-text);
    padding:9px 11px;
    min-width:80px;
    max-width:100%;
    width:100%;
    font:inherit;
    margin-top:6px;
}
.vm-console input:focus {
    outline:2px solid #cf284340;
    border-color:var(--vm-accent);
}
.vm-message {
    padding:14px 18px;
    margin:12px 0;
    border-radius:8px;
    color:#3577a2;
    background:#57a8dc12;
    line-height:1.7;
}
.vm-message.vm-error {
    color:#c94d5c;
    background:#e25d6f12;
}
.vm-empty {
    padding:48px 20px;
    text-align:center;
    color:var(--vm-muted);
}
.vm-empty>svg {
    width:42px;
    height:42px;
    margin:0 auto 12px;
}
.vm-empty h3 {
    color:var(--vm-text);
}
.vm-loading {
    color:var(--vm-accent);
    padding:10px;
}
.vm-footer {
    font-size:11px;
    color:var(--vm-muted);
    padding-top:18px;
    text-align:right;
}
.vm-overview {
    overflow:hidden;
    margin-bottom:20px;
    background:var(--vm-bg);
    border:1px solid var(--vm-line);
    border-radius:4px;
}
.vm-overview .vm-header {
    border:0;
    border-radius:0;
    padding:20px 24px;
}
.vm-overview .vm-toolbar {
    border:0;
    border-top:1px solid var(--vm-line);
    border-radius:0;
    margin:0;
    padding:12px 24px;
}
.vm-power-start {
    color:#178148!important;
    border-color:#c7e5d2!important;
    background:#f3fbf6!important;
}
.vm-access-heading {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    margin-bottom:16px;
}
.vm-access-heading .vm-access-close {
    padding:6px;
}
.vm-console-credentials {
    margin:0 0 18px;
    border-radius:4px;
}
.vm-console-credentials .vm-actions {
    margin-top:12px;
}
.vm-console .vm-actions a {
    display:inline-flex;
    align-items:center;
    gap:7px;
    border-radius:4px;
    padding:8px 12px;
    color:var(--vm-accent);
    text-decoration:none;
}
.vm-console .vm-actions a.vm-primary {
    color:#fff;
}
.vm-connection {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}
.vm-connection button {
    padding:4px 8px;
    color:var(--vm-accent);
    font-size:12px;
}
.vm-panel h3 {
    margin-bottom:4px;
}
@media(max-width:1100px) {
    .vm-toolbar {
        flex-wrap:wrap;
    }
    .vm-stats {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .vm-columns {
        grid-template-columns:1fr;
    }
}
@media(max-width:600px) {
    .vm-header {
        padding:16px;
        align-items:flex-start;
        flex-direction:column;
    }
    .vm-heading {
        gap:12px;
    }
    .vm-heading h2 {
        font-size:19px;
    }
    .vm-toolbar {
        padding:12px 16px;
        gap:12px;
    }
    .vm-stat,.vm-panel {
        padding:17px;
    }
    .vm-stats {
        gap:10px;
    }
    .vm-stat strong {
        font-size:22px;
    }
    .vm-panel dd {
        max-width:65%;
    }
    .vm-footer {
        text-align:left;
    }
    .vm-overview .vm-header {
        padding:16px;
    }
    .vm-overview .vm-toolbar {
        padding:12px 16px;
    }
    .vm-overview .vm-actions {
        width:100%;
    }
    .vm-overview .vm-toolbar .vm-actions button {
        flex:1;
        padding-inline:8px;
    }
}
</style>
@endonce
