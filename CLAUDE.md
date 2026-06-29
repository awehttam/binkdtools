# binkdtools

Scripts and tools for working with binkd, the FidoNet mailer.

## Language
All tools are written in PHP (CLI scripts).

## binkd Source Reference
The binkd C source lives at `C:\devel\binkd`. Consult it for:
- Log format: `tools.c` — `vLog()` writes `<mark> <DD> <Mon> <HH:MM:SS> [<PID>] <message>`
  - Marks: `!`=fatal(0), `?`=error(1), `+`=info(2), `-`=verbose(3), ` `=debug(4+)
- Session lifecycle: `protocol.c` — `log_end_of_session()`, `protocol()`
- File transfer log messages: `protocol.c` (`sent:`), `inbound.c` (`rcvd:`)
- Client/outgoing calls: `client.c`
- FTN address formatting: `ftnaddr.c` — `xftnaddress_to_str()`

## Key binkd Log Messages
| Message pattern | Source |
|---|---|
| `call to <addr>` | client.c:362 |
| `outgoing\|incoming session with <host>` | protocol.c:3168 |
| `addr: <addr>` | protocol.c:1351 |
| `sent: <path> (<bytes>, <cps> CPS, <addr>)` | protocol.c:2458 |
| `rcvd: <name> (<bytes>, <cps> CPS, <addr>)` | inbound.c:576 |
| `done (to\|from <addr>, OK\|failed, S/R: N/N (B/B bytes))` | protocol.c:3047 |

## FTN Address Format
`Z:N/N` or `Z:N/N.P` or `Z:N/N@domain` — see `ftnaddr.c:xftnaddress_to_str()`.
