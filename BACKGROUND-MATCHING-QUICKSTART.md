# Background Matching - Quick Start Guide

## How It Works (Visual)

### Starting a Background Job

```
┌────────────────────────────────────────────────────┐
│  Process & Export Card                             │
├────────────────────────────────────────────────────┤
│  ● In Progress                                     │
│                                                    │
│  Matched to OSM: 0 (0%)                            │
│  ░░░░░░░░░░░░░░░░░░░░░░░░░░ 0%                    │
│                                                    │
│  Generated Extracts: 0 files                       │
│                                                    │
│  ┌──────────────────┐  ┌──────────────────┐       │
│  │ ⚡ Match Overpass│  │ 🔄 Background Mode│ ◄──── Click this!
│  └──────────────────┘  └──────────────────┘       │
└────────────────────────────────────────────────────┘

                    ▼ Confirm dialog

┌─────────────────────────────────────────────────┐
│  Start background address matching?             │
│  The job will run in the background and you     │
│  can continue working.                          │
│                                                 │
│         [ Cancel ]    [ OK ]  ◄──── Click OK    │
└─────────────────────────────────────────────────┘

                    ▼ Job starts

┌────────────────────────────────────────────────────┐
│  Process & Export Card                             │
├────────────────────────────────────────────────────┤
│  ● In Progress                                     │
│                                                    │
│  Matched to OSM: 5,234 (28%)                       │
│  ████████░░░░░░░░░░░░░░░░░░░ 28%                  │
│                                                    │
│  ┌──────────────────────────────────────────┐     │
│  │ 🔄 Background matching in progress... 28%│     │
│  │ ████████████░░░░░░░░░░░░░░░░ (progress)  │     │
│  │ 5,234 / 18,333 addresses                 │ ◄── Status widget
│  └──────────────────────────────────────────┘     │
│                                                    │
│  ┌──────────────────┐                             │
│  │ ⏸️ Stop Background│  ◄── Button changes         │
│  └──────────────────┘                             │
└────────────────────────────────────────────────────┘
```

---

## Status Widget States

### 1. Running (Auto-updates every 3 seconds)

```
┌──────────────────────────────────────────┐
│ 🔄 Background matching in progress... 42%│
│ ████████████████░░░░░░░░░░░░░░░░        │
│ 7,700 / 18,333 addresses                │
└──────────────────────────────────────────┘
```

### 2. Paused

```
┌──────────────────────────────────────────┐
│ ⏸️ Background matching paused         42%│
│ ████████████████░░░░░░░░░░░░░░░░        │
│ 7,700 / 18,333 addresses                │
└──────────────────────────────────────────┘

Button: ▶️ Resume Background
```

### 3. Complete

```
┌──────────────────────────────────────────┐
│ ✅ Background matching complete      100%│
│ █████████████████████████████████████   │
│ 18,333 / 18,333 addresses               │
└──────────────────────────────────────────┘

Button: 🔄 Start New Job
```

### 4. Error

```
┌──────────────────────────────────────────┐
│ ❌ Background matching error          42%│
│ ████████████████░░░░░░░░░░░░░░░░        │
│ 7,700 / 18,333 addresses                │
└──────────────────────────────────────────┘

Button: 🔄 Retry
```

---

## Button States Flow

```
   ┌─────────────────┐
   │ 🔄 Background    │  ◄── Initial state (idle)
   │    Mode         │
   └────────┬────────┘
            │ Click
            ▼
   ┌─────────────────┐
   │ ⏸️ Stop          │  ◄── Job running
   │    Background   │
   └────────┬────────┘
            │ Click
            ▼
   ┌─────────────────┐
   │ ▶️ Resume        │  ◄── Job paused
   │    Background   │
   └────────┬────────┘
            │ Click
            ▼
   ┌─────────────────┐
   │ ⏸️ Stop          │  ◄── Back to running
   │    Background   │
   └────────┬────────┘
            │ Wait for completion
            ▼
   ┌─────────────────┐
   │ 🔄 Start New     │  ◄── Job complete
   │    Job          │
   └─────────────────┘
```

---

## Workflow Comparison

### Foreground Mode (Original)

```
User clicks "⚡ Match Overpass"
   │
   ▼
Browser sends batch requests (every 0.5s)
   │
   ▼ ▼ ▼ ▼ ▼ ▼ ▼ ▼ ▼
100 → 200 → 300 → 400 ... → 18,333
   │
   ▼
Page BLOCKED (must stay open)
Progress bar updates in real-time
Detailed logs visible
   │
   ▼
COMPLETE (2-3 minutes)
```

### Background Mode (New)

```
User clicks "🔄 Background Mode"
   │
   ▼
WP-Cron scheduled (immediate)
   │
   ▼
Server processes batches (every 2s)
   │
   ▼ ▼ ▼ ▼ ▼ ▼ ▼ ▼ ▼
100 → 200 → 300 → 400 ... → 18,333
   │
   ▼
Page FREE (can close browser!)
Status polling updates widget (every 3s)
Summary stats only
   │
   ▼
COMPLETE (5-7 minutes)

User can:
- Navigate to other pages ✓
- Close browser ✓
- Do other work ✓
- Check status anytime ✓
```

---

## Real-Time Updates

### Polling Cycle (3 seconds)

```
Time    Widget Display                      Backend Status
────────────────────────────────────────────────────────────
0s      5,234 / 18,333 (28%)               Running...
3s      5,634 / 18,333 (30%)  ◄─ Polled    Running...
6s      6,034 / 18,333 (32%)  ◄─ Polled    Running...
9s      6,434 / 18,333 (35%)  ◄─ Polled    Running...
...
300s    18,333 / 18,333 (100%) ◄─ Polled   Complete!
```

No manual refresh needed - updates automatically!

---

## Multi-Tab Behavior

### Scenario: User opens 3 browser tabs

```
Tab 1 (Address Management)              Tab 2 (Products)             Tab 3 (Teams)
┌─────────────────────────┐              ┌─────────────┐              ┌──────────────┐
│ 🔄 Status: 42%          │              │ (editing    │              │ (adding team)│
│ Auto-polling active     │              │  products)  │              │              │
│ Updates every 3s        │              │             │              │              │
└─────────────────────────┘              └─────────────┘              └──────────────┘
         ▲                                      ▲                            ▲
         │                                      │                            │
         └──────────────────────────────────────┴────────────────────────────┘
                                    │
                              Server (WP-Cron)
                           Processing batches
                          independently of tabs
```

**Key Point:** Background job runs on the **server**, not in browser tabs!

---

## When to Use Each Mode

### Use Foreground Mode (⚡ Match Overpass) When:

✓ **Small datasets** (<5,000 addresses)  
✓ **Need immediate feedback** (watching logs in real-time)  
✓ **Testing/Debugging** (want detailed error messages)  
✓ **One-time task** (won't be interrupted)  
✓ **Fast completion** wanted (aggressive batching)

### Use Background Mode (🔄 Background Mode) When:

✓ **Large datasets** (>5,000 addresses)  
✓ **Multitasking** needed (other admin work to do)  
✓ **Unreliable connection** (server-side is safer)  
✓ **Long-running** process (5+ minutes)  
✓ **Can check later** (don't need instant updates)  
✓ **Want to close browser** (come back later)

---

## Troubleshooting

### "Background matching is currently running. Do you want to STOP it?"

**What happened:** Clicked button while job is running  
**Options:**
- Click **Cancel** → Keep job running
- Click **OK** → Pause job (can resume later)

### Widget shows old percentage

**Cause:** Polling hasn't updated yet (3-second interval)  
**Solution:** Wait 3 seconds for next update

### Button says "Retry" with error icon

**Cause:** Job encountered an error  
**Solution:**
1. Check error in status widget
2. Click "Retry" to start fresh job
3. If persists, check server logs

### Job stuck at same percentage

**Possible causes:**
- WP-Cron disabled on server
- Server overloaded
- Overpass API timeout

**Solution:**
1. Wait 30 seconds (might be slow batch)
2. Check WP-Cron: `wp cron event list`
3. Stop and retry in foreground mode
4. Contact hosting provider if WP-Cron is disabled

---

## Best Practices

### ✅ DO

- Use background mode for large datasets
- Check status periodically (every few minutes)
- Let job complete before starting new one
- Use foreground mode for quick tests

### ❌ DON'T

- Start multiple background jobs simultaneously
- Disable WP-Cron on your server
- Expect instant updates (3-second polling delay)
- Force-refresh page rapidly (causes extra polling)

---

## Quick Reference

| Feature | Foreground | Background |
|---------|-----------|------------|
| **Icon** | ⚡ | 🔄 |
| **Blocking** | Yes | No |
| **Browser** | Must stay open | Can close |
| **Speed** | Fast (~2 min for 18K) | Slower (~6 min for 18K) |
| **Updates** | Real-time | 3-second poll |
| **Logs** | Detailed | Summary |
| **Best for** | Small (<5K) | Large (>5K) |

---

**Ready to try it?** Click "🔄 Background Mode" and watch the magic happen! ✨
