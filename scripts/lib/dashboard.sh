#!/usr/bin/env bash
# shellcheck shell=bash
# =============================================================================
# Dashboard de consola para los scripts de staging.
#
# Dibuja una sola pantalla con dos secciones fijas (sin scroll):
#
#   ╭─ Autotributo staging ──────────────────────────────────────────╮
#   │ create · us-east-2 · t3.micro             2/6 · 00:41         │
#   ╰────────────────────────────────────────────────────────────────╯
#
#   ╭─ Etapas ───────────────────────────────────────────────────────╮
#   │  ✔  1/6  Verificar imagen y entorno                     OK     │
#   │  ✔  2/6  Crear instancia EC2                            OK     │
#   │  ⠹  3/6  Provisionar host (Docker, swap, UFW, DuckDNS)  12s    │
#   │  ○  4/6  Desplegar la aplicación                        —      │
#   ╰────────────────────────────────────────────────────────────────╯
#
#   ╭─ En curso ─────────────────────────────────────────────────────╮
#   │ ⠹ Provisionando el host                                        │
#   │ $ ssh ubuntu@3.140.10.8 sudo /opt/autotributo/scripts/...      │
#   │                                                                │
#   │   instalando docker...                                         │
#   ╰────────────────────────────────────────────────────────────────╯
#
# Inspirado en la TUI de `artisan dev` (@laravel/multiplex) y en Laravel
# Prompts: buffer alterno, marco fijo, spinner animado y redibujado completo
# en una sola escritura (sin parpadeo y sin ensuciar el scrollback).
#
# Uso:
#   source "$SCRIPT_DIR/../lib/dashboard.sh"
#
#   dash_init "Autotributo staging" "create"
#   dash_plan "Paso 1" "Paso 2" "Paso 3"
#   dash_stage 1
#   dash_note "Leyendo configuración"
#   dash_run "Resolviendo la AMI" aws ec2 describe-images ...
#   dash_stage_done
#   dash_finish ok "https://staging.example.com"
#   # dash_fail "mensaje" aborta con código 1 y marca la etapa en rojo.
#
# Al salir (éxito o error) se recupera la pantalla normal y se imprime un
# resumen en texto plano que queda en el scrollback, con la salida completa
# en un archivo temporal cuando algo falla.
#
# Compatible con Bash 3.2 (macOS) y 5.x (Ubuntu). Dependencias externas:
# solo `tail`, `tr` y `sed`. Si stdout no es una terminal (CI, pipes, logs de
# GitHub Actions) o TERM=dumb, cambia solo a modo `inline` con la misma
# información en texto plano. Se puede forzar con DASH_MODE=inline|full.
# =============================================================================

if [[ -n "${AUTOTRIBUTO_DASHBOARD_SH:-}" ]]; then
    return 0
fi
AUTOTRIBUTO_DASHBOARD_SH=1

# --- estado ----------------------------------------------------------------
DASH_MODE="${DASH_MODE:-auto}" # auto | full | inline
DASH_TITLE=""
DASH_MODE_LABEL=""
DASH_META=""
DASH_STAGES=()
DASH_STATES=()
DASH_NOTES=()
DASH_STARTED=()
DASH_STOPPED=()
DASH_N=0
DASH_ACTIVITY="Preparando"
DASH_CMD=""
DASH_TICK=0
DASH_T0=${SECONDS:-0}
DASH_ACTIVE=0
DASH_OK=0
DASH_ERROR=""
DASH_FINISH_MSG=""
DASH_BG_PID=""
DASH_PROC_H=8
DASH_STAGES_H=3
DASH_COMPACT=0
DASH_ROWS=24
DASH_COLS=80
DASH_RESIZED=1
DASH_SIZE_OK=0
DASH_TMP=""
DASH_TAIL_KEY=""
DASH_TAIL_CACHE=""
DASH_OUT_LOG=""
DASH_ERR_LOG=""
DASH_SESSION_LOG=""

DASH_COLOR=${DASH_COLOR:-0}
DASH_UTF8=${DASH_UTF8:-1}
DASH_SPIN_FRAMES=("⠋" "⠙" "⠹" "⠸" "⠼" "⠴" "⠦" "⠧" "⠇" "⠏")
DASH_SPIN_COUNT=10
DASH_ICON_DONE="✔"
DASH_ICON_FAIL="✖"
DASH_ICON_PEND="○"
DASH_ICON_WARN="!"
DASH_TXT_FAIL="FALLÓ"
DASH_TXT_WARN="AVISO"

# --- utilidades internas ---------------------------------------------------

_dash_setup_glyphs() {
    if [[ "$DASH_UTF8" == "1" ]]; then
        DASH_SPIN_FRAMES=("⠋" "⠙" "⠹" "⠸" "⠼" "⠴" "⠦" "⠧" "⠇" "⠏")
        DASH_SPIN_COUNT=10
        DASH_ICON_DONE="✔"
        DASH_ICON_FAIL="✖"
        DASH_ICON_PEND="○"
        DASH_ICON_WARN="!"
        DASH_TXT_FAIL="FALLÓ"
        DASH_TXT_WARN="AVISO"
    else
        DASH_SPIN_FRAMES=("|" "/" "-" "\\")
        DASH_SPIN_COUNT=4
        DASH_ICON_DONE="+"
        DASH_ICON_FAIL="x"
        DASH_ICON_PEND="."
        DASH_ICON_WARN="!"
        DASH_TXT_FAIL="FALLO"
        DASH_TXT_WARN="AVISO"
    fi
}

_dash_setup_colors() {
    if [[ "$DASH_COLOR" == "1" ]]; then
        C_RESET=$'\033[0m'
        C_BORDER=$'\033[90m'
        C_TITLE=$'\033[1;36m'
        C_OK=$'\033[32m'
        C_RUN=$'\033[33m'
        C_FAIL=$'\033[31m'
        C_WARN=$'\033[33m'
        C_DIM=$'\033[90m'
        C_ERR=$'\033[31m'
    else
        C_RESET=""
        C_BORDER=""
        C_TITLE=""
        C_OK=""
        C_RUN=""
        C_FAIL=""
        C_WARN=""
        C_DIM=""
        C_ERR=""
    fi
}

_dash_repeat() {
    # $1 = carácter, $2 = ancho -> _DASH_REPEAT
    local ch="$1" n="$2"
    if (( n <= 0 )); then
        _DASH_REPEAT=""
        return 0
    fi
    printf -v _DASH_PAD '%*s' "$n" ''
    _DASH_REPEAT="${_DASH_PAD// /$ch}"
}

_dash_fit() {
    # $1 = texto, $2 = ancho visible -> _DASH_FIT (recortado y rellenado)
    local t="$1" w="$2" n
    t="${t//$'\t'/  }"
    t="${t//$'\n'/ }"
    n=${#t}
    if (( n > w )); then
        if (( w <= 3 )); then
            t="${t:0:w}"
        elif [[ "$DASH_UTF8" == "1" ]]; then
            t="${t:0:w-1}…"
        else
            t="${t:0:w-3}..."
        fi
        n=${#t}
    fi
    _dash_repeat ' ' $((w - n))
    _DASH_FIT="${t}${_DASH_REPEAT}"
}

_dash_elapsed() {
    local s=$(( SECONDS - DASH_T0 ))
    (( s < 0 )) && s=0
    printf -v _DASH_ELAPSED '%02d:%02d' $((s / 60)) $((s % 60))
}

_dash_stage_time() {
    # $1 = índice -> _DASH_STAGE_TIME ("12s")
    local i="$1" end diff
    if [[ "${DASH_STATES[$i]}" == "running" ]]; then
        end=$SECONDS
    else
        end="${DASH_STOPPED[$i]:-$SECONDS}"
    fi
    diff=$(( end - ${DASH_STARTED[$i]:-$end} ))
    (( diff < 0 )) && diff=0
    printf -v _DASH_STAGE_TIME '%ds' "$diff"
}

_dash_size() {
    local rows="" cols="" size=""
    # `tput lines` dentro de una sustitución de comandos lee el tamaño por
    # defecto de terminfo (24x80), no el de la terminal: `stty size` sí toma
    # el tamaño real del dispositivo de control.
    size="$(stty size < /dev/tty 2>/dev/null || true)"
    rows="${size%% *}"
    cols="${size##* }"
    if ! [[ "$rows" =~ ^[0-9]+$ ]] || ! [[ "$cols" =~ ^[0-9]+$ ]]; then
        rows="$(tput lines 2>/dev/null || true)"
        cols="$(tput cols 2>/dev/null || true)"
    fi
    [[ "$rows" =~ ^[0-9]+$ ]] || rows="${LINES:-24}"
    [[ "$cols" =~ ^[0-9]+$ ]] || cols="${COLUMNS:-80}"
    (( rows < 8 )) && rows=8
    (( cols < 40 )) && cols=40
    # Un marco demasiado ancho se lee peor: se limita a 100 columnas.
    (( cols > 100 )) && cols=100
    DASH_ROWS=$rows
    DASH_COLS=$cols
}

_dash_layout() {
    local budget=$(( DASH_ROWS - 1 ))
    local fixed proc
    DASH_COMPACT=0
    DASH_STAGES_H=$(( DASH_N + 2 ))
    fixed=$(( 3 + 1 + DASH_STAGES_H + 1 ))
    proc=$(( budget - fixed ))

    # Terminal baja: las etapas se resumen en una sola línea y la caja de
    # proceso se queda con el espacio restante.
    if (( proc < 7 )); then
        DASH_COMPACT=1
        DASH_STAGES_H=3
        fixed=$(( 3 + 1 + 3 + 1 ))
        proc=$(( budget - fixed ))
    fi
    (( proc < 5 )) && proc=5
    DASH_PROC_H=$proc
}

# --- construcción del marco ------------------------------------------------

_dash_top() {
    # $1 = título de la caja
    local title len r
    _dash_fit "$1" $(( DASH_COLS - 8 ))
    title="$_DASH_FIT"
    # Recorta el relleno que añade _dash_fit.
    title="${title%"${title##*[! ]}"}"
    len=${#title}
    r=$(( DASH_COLS - 5 - len ))
    _dash_repeat '─' "$r"
    DASH_FRAME+="${C_BORDER}╭─ ${C_TITLE}${title}${C_RESET}${C_BORDER} ${_DASH_REPEAT}╮${C_RESET}"$'\n'
}

_dash_bottom() {
    _dash_repeat '─' $(( DASH_COLS - 2 ))
    DASH_FRAME+="${C_BORDER}╰${_DASH_REPEAT}╯${C_RESET}"$'\n'
}

_dash_row() {
    # $1 = contenido con ancho visible DASH_COLS-4
    DASH_FRAME+="${C_BORDER}│${C_RESET} $1 ${C_BORDER}│${C_RESET}"$'\n'
}

_dash_pair() {
    # $1 = izquierda, $2 = derecha, $3 = ancho -> _DASH_ROW
    local left="$1" right="$2" w="$3" gap
    _dash_fit "$left" $(( w - ${#right} - 2 ))
    left="$_DASH_FIT"
    gap=$(( w - ${#left} - ${#right} ))
    (( gap < 2 )) && gap=2
    _dash_repeat ' ' "$gap"
    _DASH_ROW="${left}${_DASH_REPEAT}${right}"
}

_dash_stage_row() {
    # $1 = índice, $2 = ancho -> _DASH_ROW (con color)
    local i="$1" w="$2" label wnum num icon color stxt scolor label_w fixed
    label="${DASH_STAGES[$i]}"
    num="$(( i + 1 ))/${DASH_N}"
    wnum=${#num}

    case "${DASH_STATES[$i]}" in
        done)
            icon="$DASH_ICON_DONE"; color="$C_OK"; stxt="OK"; scolor="$C_OK"
            ;;
        failed)
            icon="$DASH_ICON_FAIL"; color="$C_FAIL"; stxt="$DASH_TXT_FAIL"; scolor="$C_FAIL"
            ;;
        warn)
            icon="$DASH_ICON_WARN"; color="$C_WARN"; stxt="$DASH_TXT_WARN"; scolor="$C_WARN"
            ;;
        running)
            icon="${DASH_SPIN_FRAMES[$(( DASH_TICK % DASH_SPIN_COUNT ))]}"
            color="$C_RUN"
            _dash_stage_time "$i"
            stxt="$_DASH_STAGE_TIME"
            scolor="$C_RUN"
            ;;
        *)
            icon="$DASH_ICON_PEND"; color="$C_DIM"; stxt=""; scolor="$C_DIM"
            ;;
    esac

    # " " icono " " num "  " label " " estado — el ancho se reparte exacto.
    if [[ -n "$stxt" ]]; then
        label_w=$(( w - wnum - 6 - ${#stxt} ))
    else
        label_w=$(( w - wnum - 5 ))
    fi
    if (( label_w < 6 )) && [[ -n "$stxt" ]]; then
        stxt=""
        label_w=$(( w - wnum - 5 ))
    fi
    (( label_w < 4 )) && label_w=4

    _dash_fit "$label" "$label_w"
    _DASH_ROW=" ${color}${icon}${C_RESET} ${C_DIM}${num}${C_RESET}  ${_DASH_FIT}"
    if [[ -n "$stxt" ]]; then
        _DASH_ROW+=" ${scolor}${stxt}${C_RESET}"
    fi
}

_dash_tail_lines() {
    # $1 = filas -> _DASH_TAIL (texto con saltos de línea)
    #
    # La salida de npm/vite/composer/docker compose no es "líneas": usa \r para
    # repintar la misma línea (progreso) y códigos ANSI para borrar o mover el
    # cursor. Si se tratan como líneas sueltas, cientos de "transforming…"
    # desplazan la salida real y quedan visibles cuando el comando ya avanzó.
    # Aquí se conserva solo el estado final de cada línea física (último
    # segmento tras \r), se descartan los códigos de control y se eliminan las
    # repeticiones consecutivas que deja el repintado.
    local n="$1"
    _DASH_TAIL=""
    [[ -s "$DASH_OUT_LOG" || -s "$DASH_ERR_LOG" ]] || return 0

    # El panel se redibuja cada 120 ms y procesar la salida cuesta; si nada
    # cambió desde el último frame se reutiliza el resultado.
    local size key
    size=$(( $(wc -c <"$DASH_OUT_LOG" 2>/dev/null || printf 0) + $(wc -c <"$DASH_ERR_LOG" 2>/dev/null || printf 0) ))
    key="$n:$size"
    if [[ "$key" == "${DASH_TAIL_KEY:-}" ]]; then
        _DASH_TAIL="${DASH_TAIL_CACHE:-}"
        return 0
    fi
    DASH_TAIL_KEY="$key"

    _DASH_TAIL="$(
        cat "$DASH_OUT_LOG" "$DASH_ERR_LOG" 2>/dev/null \
            | tail -c 16384 \
            | awk '
                {
                    tabs = $0
                    gsub(/\t/, "  ", tabs)
                    count = split(tabs, chunks, "\r")
                    line = chunks[count]
                    gsub(/\033\[[0-9;?]*[A-Za-z]/, "", line)
                    gsub(/[[:cntrl:]]/, "", line)
                    if (line == "") next
                    if (line == previous) next
                    previous = line
                    print line
                }' \
            | tail -n "$n"
    )"
    DASH_TAIL_CACHE="$_DASH_TAIL"
}

_dash_paint() {
    [[ "$DASH_MODE" == "full" ]] || return 0

    if [[ "${DASH_RESIZED:-0}" == "1" || "${DASH_SIZE_OK:-0}" != "1" || $(( DASH_TICK % 40 )) -eq 0 ]]; then
        _dash_size
        DASH_SIZE_OK=1
        if [[ "${DASH_RESIZED:-0}" == "1" ]]; then
            DASH_CLEAR=1
            DASH_RESIZED=0
        fi
    fi

    _dash_layout
    _dash_elapsed

    local inner=$(( DASH_COLS - 4 ))
    local i spin w
    DASH_FRAME=""

    if [[ "${DASH_CLEAR:-0}" == "1" ]]; then
        DASH_FRAME+=$'\033[1;1H\033[2J'
        DASH_CLEAR=0
    fi
    DASH_FRAME+=$'\033[1;1H'

    # Cabecera: título, contexto y progreso/tiempo.
    _dash_top "$DASH_TITLE"
    local left="$DASH_MODE_LABEL"
    [[ -n "$DASH_META" ]] && left="$left · $DASH_META"
    local done_count=0
    for (( i = 0; i < DASH_N; i++ )); do
        case "${DASH_STATES[$i]}" in
            done | warn | failed) done_count=$(( done_count + 1 )) ;;
        esac
    done
    local clock="⏱ ${_DASH_ELAPSED}"
    [[ "$DASH_UTF8" == "1" ]] || clock="${_DASH_ELAPSED}"
    _dash_pair "$left" "${done_count}/${DASH_N} · ${clock}" "$inner"
    _dash_row "${C_DIM}${_DASH_ROW}${C_RESET}"
    _dash_bottom
    DASH_FRAME+=$'\n'

    # Sección de etapas.
    _dash_top "Etapas"
    if [[ "$DASH_COMPACT" == "1" ]]; then
        # Una sola línea: iconos de todas las etapas + la que está en curso.
        local plain="" colored="" entry="" avail_c fill pad
        for (( i = 0; i < DASH_N; i++ )); do
            case "${DASH_STATES[$i]}" in
                done) plain+="$DASH_ICON_DONE "; colored+="${C_OK}${DASH_ICON_DONE}${C_RESET} " ;;
                failed) plain+="$DASH_ICON_FAIL "; colored+="${C_FAIL}${DASH_ICON_FAIL}${C_RESET} " ;;
                warn) plain+="$DASH_ICON_WARN "; colored+="${C_WARN}${DASH_ICON_WARN}${C_RESET} " ;;
                running)
                    plain+="${DASH_SPIN_FRAMES[$(( DASH_TICK % DASH_SPIN_COUNT ))]} "
                    colored+="${C_RUN}${DASH_SPIN_FRAMES[$(( DASH_TICK % DASH_SPIN_COUNT ))]}${C_RESET} "
                    ;;
                *) plain+="$DASH_ICON_PEND "; colored+="${C_DIM}${DASH_ICON_PEND}${C_RESET} " ;;
            esac
            if [[ "${DASH_STATES[$i]}" == "running" ]]; then
                entry="$(( i + 1 ))/${DASH_N} ${DASH_STAGES[$i]}"
            fi
        done
        avail_c=$(( inner - 2 - ${#plain} ))
        (( avail_c < 8 )) && avail_c=8
        _dash_fit "$entry" "$avail_c"
        entry="$_DASH_FIT"
        pad=$(( inner - 1 - ${#plain} - ${#entry} ))
        (( pad < 0 )) && pad=0
        _dash_repeat ' ' "$pad"
        fill="$_DASH_REPEAT"
        _dash_row " ${colored}${entry}${fill}"
    else
        for (( i = 0; i < DASH_N; i++ )); do
            _dash_stage_row "$i" "$inner"
            _dash_row "$_DASH_ROW"
        done
    fi
    _dash_bottom
    DASH_FRAME+=$'\n'

    # Sección de proceso en curso.
    _dash_top "En curso"
    local interior=$(( DASH_PROC_H - 2 ))
    spin="${DASH_SPIN_FRAMES[$(( DASH_TICK % DASH_SPIN_COUNT ))]}"

    _dash_fit "${spin} ${DASH_ACTIVITY}" "$inner"
    _dash_row "${C_RUN}${_DASH_FIT}${C_RESET}"

    local avail=$(( interior - 1 ))
    local cmd_rows=0
    if [[ -n "$DASH_CMD" ]]; then
        cmd_rows=1
        if (( ${#DASH_CMD} + 2 > inner && avail >= 3 )); then
            cmd_rows=2
        fi
        (( cmd_rows > avail )) && cmd_rows=1

        local first="\$ ${DASH_CMD}" second=""
        if (( cmd_rows == 2 )); then
            # Se corta en el último espacio, pero solo si no deja la primera
            # línea casi vacía (en terminales estrechas se corta seco).
            first="${DASH_CMD:0:inner}"
            if [[ "$first" == *" "* ]]; then
                local cut="${first% *}"
                if (( ${#cut} * 2 >= inner )); then
                    second="${DASH_CMD:$(( ${#cut} + 1 ))}"
                    first="$cut"
                else
                    second="${DASH_CMD:inner}"
                fi
            else
                second="${DASH_CMD:inner}"
            fi
            first="\$ ${first}"
        fi
        _dash_fit "$first" "$inner"
        _dash_row "${C_DIM}${_DASH_FIT}${C_RESET}"
        if (( cmd_rows == 2 )); then
            _dash_fit "$second" "$inner"
            _dash_row "${C_DIM}${_DASH_FIT}${C_RESET}"
        fi
    fi

    local tail_rows=$(( avail - cmd_rows - 1 ))
    if (( tail_rows < 1 )); then
        tail_rows=$(( avail - cmd_rows ))
    fi
    (( tail_rows < 0 )) && tail_rows=0

    if (( tail_rows > 0 && avail - cmd_rows > tail_rows )); then
        _dash_fit "" "$inner"
        _dash_row "$_DASH_FIT"
    fi

    local used=$(( 1 + cmd_rows ))
    if (( tail_rows > 0 )); then
        _dash_tail_lines "$tail_rows"
        local rest="$_DASH_TAIL" line printed=0 lcolor
        while [[ -n "$rest" && $printed -lt $tail_rows ]]; do
            if [[ "$rest" == *$'\n'* ]]; then
                line="${rest%%$'\n'*}"
                rest="${rest#*$'\n'}"
            else
                line="$rest"
                rest=""
            fi
            lcolor="$C_DIM"
            case "$line" in
                *[Ee][Rr][Rr][Oo][Rr]* | *[Ff][Aa][Ii][Ll]* | *[Dd][Ee][Nn][Ii][Ee][Dd]*) lcolor="$C_ERR" ;;
            esac
            _dash_fit "  ${line}" "$inner"
            _dash_row "${lcolor}${_DASH_FIT}${C_RESET}"
            printed=$(( printed + 1 ))
        done
        used=$(( used + 1 + printed ))
    fi

    # Relleno: la caja conserva siempre la misma altura para que el marco no
    # salte entre redibujados (sin scroll ni pantalla temblorosa).
    while (( used < interior )); do
        _dash_fit "" "$inner"
        _dash_row "$_DASH_FIT"
        used=$(( used + 1 ))
    done

    _dash_bottom

    printf '%s' "$DASH_FRAME"
    return 0
}

# --- resumen final ---------------------------------------------------------

_dash_summary() {
    local i state icon color line dur total
    total=$(( SECONDS - DASH_T0 ))
    (( total < 0 )) && total=0

    if [[ "$DASH_MODE" == "full" ]]; then
        printf '\n'
    fi
    if [[ "$DASH_OK" == "1" ]]; then
        printf '%s%s%s %s · %s · %02d:%02d\n' \
            "$C_OK" "$DASH_ICON_DONE" "$C_RESET" \
            "$DASH_TITLE" "$DASH_MODE_LABEL" $((total / 60)) $((total % 60))
    else
        printf '%s%s%s %s · %s · %02d:%02d\n' \
            "$C_FAIL" "$DASH_ICON_FAIL" "$C_RESET" \
            "$DASH_TITLE" "$DASH_MODE_LABEL" $((total / 60)) $((total % 60))
    fi

    for (( i = 0; i < DASH_N; i++ )); do
        state="${DASH_STATES[$i]}"
        case "$state" in
            done) icon="$DASH_ICON_DONE"; color="$C_OK" ;;
            failed) icon="$DASH_ICON_FAIL"; color="$C_FAIL" ;;
            warn) icon="$DASH_ICON_WARN"; color="$C_WARN" ;;
            running) icon="$DASH_ICON_FAIL"; color="$C_FAIL" ;;
            *) icon="$DASH_ICON_PEND"; color="$C_DIM" ;;
        esac
        dur=""
        if [[ -n "${DASH_STARTED[$i]:-}" ]]; then
            dur="$(( ${DASH_STOPPED[$i]:-$SECONDS} - ${DASH_STARTED[$i]} ))s"
        fi
        _dash_fit "$(( i + 1 ))/${DASH_N}  ${DASH_STAGES[$i]}" 46
        printf '  %s%s%s  %s %s\n' "$color" "$icon" "$C_RESET" "$_DASH_FIT" "$dur"
        if [[ -n "${DASH_NOTES[$i]:-}" ]]; then
            printf '      %s↳ %s%s\n' "$C_DIM" "${DASH_NOTES[$i]}" "$C_RESET"
        fi
    done

    if [[ -n "$DASH_FINISH_MSG" ]]; then
        printf '\n  %s\n' "$DASH_FINISH_MSG"
    fi
    if [[ -n "$DASH_ERROR" ]]; then
        printf '\n  %sERROR:%s %s\n' "$C_FAIL" "$C_RESET" "$DASH_ERROR"
        if [[ -n "$DASH_SESSION_LOG" && -s "$DASH_SESSION_LOG" ]]; then
            printf '  %súltimas líneas:%s\n' "$C_DIM" "$C_RESET"
            tail -n 10 "$DASH_SESSION_LOG" | sed 's/^/    /'
        fi
    fi
    if [[ "$DASH_OK" != "1" && -n "$DASH_SESSION_LOG" && -s "$DASH_SESSION_LOG" ]]; then
        printf '  %ssalida completa:%s %s\n' "$C_DIM" "$C_RESET" "$DASH_SESSION_LOG"
    fi
    printf '\n'
}

# --- ciclo de vida ---------------------------------------------------------

_dash_cleanup() {
    local status=$?
    if [[ "${DASH_ACTIVE:-0}" != "1" ]]; then
        return 0
    fi
    DASH_ACTIVE=0
    if [[ -n "${DASH_BG_PID:-}" ]]; then
        kill "$DASH_BG_PID" 2>/dev/null || true
    fi
    if [[ "$DASH_MODE" == "full" ]]; then
        printf '%s\033[?25h\033[?1049l' "$C_RESET"
    fi
    _dash_summary
    if [[ "$DASH_OK" == "1" && -n "$DASH_TMP" ]]; then
        rm -rf "$DASH_TMP"
    fi
    return "$status"
}

_dash_interrupt() {
    DASH_OK=0
    DASH_ERROR="${DASH_ERROR:-cancelado por el usuario}"
    exit 130
}

dash_init() {
    DASH_TITLE="${1:-Script}"
    DASH_MODE_LABEL="${2:-run}"

    local loc="${LC_ALL:-${LC_CTYPE:-${LANG:-}}}"
    if [[ "$loc" == *[Uu][Tt][Ff]-8* || "$loc" == *[Uu][Tt][Ff]8* ]]; then
        DASH_UTF8=1
    else
        DASH_UTF8=0
    fi

    if [[ "$DASH_MODE" == "auto" ]]; then
        if [[ -t 1 && "${TERM:-dumb}" != "dumb" ]]; then
            DASH_MODE="full"
        else
            DASH_MODE="inline"
        fi
    fi

    if [[ -z "${NO_COLOR:-}" && "$DASH_MODE" == "full" && "${TERM:-}" != "dumb" ]]; then
        DASH_COLOR=1
    else
        DASH_COLOR="${DASH_COLOR:-0}"
    fi

    _dash_setup_glyphs
    _dash_setup_colors

    local tmp_base="${TMPDIR:-/tmp}"
    tmp_base="${tmp_base%/}"
    DASH_TMP="$(mktemp -d "$tmp_base/autotributo-dash.XXXXXX")"
    DASH_OUT_LOG="$DASH_TMP/stdout.log"
    DASH_ERR_LOG="$DASH_TMP/stderr.log"
    DASH_SESSION_LOG="$DASH_TMP/session.log"
    : >"$DASH_OUT_LOG"
    : >"$DASH_ERR_LOG"
    DASH_TAIL_KEY=""
    : >"$DASH_SESSION_LOG"

    DASH_ACTIVE=1
    DASH_OK=0
    DASH_STARTED=()
    DASH_STOPPED=()
    DASH_TICK=0
    DASH_T0=$SECONDS
    DASH_RESIZED=1
    DASH_SIZE_OK=0

    trap '_dash_cleanup' EXIT
    trap '_dash_interrupt' INT TERM
    trap 'DASH_RESIZED=1' WINCH

    if [[ "$DASH_MODE" == "full" ]]; then
        _dash_size
        if (( DASH_ROWS < 14 || DASH_COLS < 50 )); then
            DASH_MODE="inline"
        fi
    fi

    if [[ "$DASH_MODE" == "full" ]]; then
        printf '\033[?1049h\033[?25l'
        _dash_paint
    else
        printf '%s · %s\n' "$DASH_TITLE" "$DASH_MODE_LABEL"
    fi
}

dash_plan() {
    DASH_STAGES=("$@")
    DASH_N=${#DASH_STAGES[@]}
    DASH_STATES=()
    DASH_NOTES=()
    local i
    for (( i = 0; i < DASH_N; i++ )); do
        DASH_STATES[$i]="pending"
    done
    [[ "$DASH_MODE" == "full" ]] && _dash_paint
    return 0
}

dash_stage() {
    # $1 = número de etapa (1-based)
    local idx=$(( ${1:-1} - 1 )) i
    (( idx < 0 )) && idx=0
    (( idx >= DASH_N )) && idx=$(( DASH_N - 1 ))

    for (( i = 0; i < idx; i++ )); do
        if [[ "${DASH_STATES[$i]}" == "running" ]]; then
            DASH_STATES[$i]="done"
            DASH_STOPPED[$i]=$SECONDS
        fi
    done

    DASH_STATES[$idx]="running"
    DASH_STARTED[$idx]=$SECONDS
    DASH_ACTIVITY="${DASH_STAGES[$idx]}"
    DASH_CMD=""
    : >"$DASH_OUT_LOG"
    : >"$DASH_ERR_LOG"
    DASH_TAIL_KEY=""

    if [[ "$DASH_MODE" == "inline" ]]; then
        printf '\n[%d/%d] %s\n' "$(( idx + 1 ))" "$DASH_N" "${DASH_STAGES[$idx]}"
    else
        _dash_paint
    fi
    return 0
}

dash_stage_done() {
    # $1 = índice opcional (por defecto, la etapa en curso)
    local idx="${1:-}" i
    if [[ -z "$idx" ]]; then
        for (( i = 0; i < DASH_N; i++ )); do
            if [[ "${DASH_STATES[$i]}" == "running" ]]; then
                idx=$(( i + 1 ))
                break
            fi
        done
    fi
    [[ -z "$idx" ]] && return 0
    idx=$(( idx - 1 ))
    DASH_STATES[$idx]="done"
    DASH_STOPPED[$idx]=$SECONDS
    if [[ "$DASH_MODE" == "inline" ]]; then
        _dash_stage_time "$idx"
        printf '  ok (%s)\n' "$_DASH_STAGE_TIME"
    else
        _dash_paint
    fi
    return 0
}

dash_stage_warn() {
    # dash_stage_warn [motivo]
    # Marca la etapa en curso como completada con aviso (no aborta). El motivo
    # se guarda y se imprime en el resumen final, para que "AVISO" no quede
    # como una palabra suelta sin explicación.
    local reason="${1:-}" idx="" i
    for (( i = 0; i < DASH_N; i++ )); do
        if [[ "${DASH_STATES[$i]}" == "running" ]]; then
            idx=$(( i + 1 ))
            break
        fi
    done
    [[ -z "$idx" ]] && return 0
    idx=$(( idx - 1 ))
    DASH_STATES[$idx]="warn"
    DASH_NOTES[$idx]="$reason"
    DASH_STOPPED[$idx]=$SECONDS
    if [[ "$DASH_MODE" == "inline" && -n "$reason" ]]; then
        printf '  aviso: %s\n' "$reason"
    fi
    [[ "$DASH_MODE" == "full" ]] && _dash_paint
    return 0
}

dash_stage_fail() {
    local idx="${1:-}" i
    if [[ -z "$idx" ]]; then
        for (( i = 0; i < DASH_N; i++ )); do
            if [[ "${DASH_STATES[$i]}" == "running" ]]; then
                idx=$(( i + 1 ))
                break
            fi
        done
    fi
    [[ -z "$idx" ]] && return 0
    idx=$(( idx - 1 ))
    DASH_STATES[$idx]="failed"
    DASH_STOPPED[$idx]=$SECONDS
    [[ "$DASH_MODE" == "full" ]] && _dash_paint
    return 0
}

dash_note() {
    DASH_ACTIVITY="$*"
    # Entre comandos no hay nada en ejecución: se limpia la línea del comando
    # para no dejar uno viejo como si siguiera corriendo.
    DASH_CMD=""
    [[ "$DASH_MODE" == "full" ]] && _dash_paint
    return 0
}

dash_meta() {
    DASH_META="$*"
    [[ "$DASH_MODE" == "full" ]] && _dash_paint
    return 0
}

dash_wait() {
    # Espera animada: $1 = segundos
    local n="${1:-1}" end
    if [[ "$DASH_MODE" != "full" ]]; then
        sleep "$n"
        return 0
    fi
    end=$(( SECONDS + n ))
    while (( SECONDS < end )); do
        DASH_TICK=$(( DASH_TICK + 1 ))
        _dash_paint
        sleep 0.12
    done
    return 0
}

dash_run() {
    # dash_run "descripción" comando [args...]
    # Ejecuta el comando capturando la salida y redibujando el panel mientras
    # corre. Devuelve el código de salida del comando.
    local label="$1"
    shift
    DASH_ACTIVITY="$label"
    if [[ -n "${DASH_CMD_OVERRIDE:-}" ]]; then
        DASH_CMD="$DASH_CMD_OVERRIDE"
        DASH_CMD_OVERRIDE=""
    else
        DASH_CMD="$*"
    fi

    if [[ "$DASH_MODE" != "full" ]]; then
        if [[ -n "${DASH_CMD_OVERRIDE:-}" ]]; then
            printf '  $ %s\n' "$DASH_CMD_OVERRIDE"
            DASH_CMD_OVERRIDE=""
        else
            printf '  $ %s\n' "$*"
        fi
        local irc=0
        "$@" || irc=$?
        if (( irc != 0 )); then
            printf '  falló (código %d)\n' "$irc"
        fi
        return "$irc"
    fi

    : >"$DASH_OUT_LOG"
    : >"$DASH_ERR_LOG"
    DASH_TAIL_KEY=""
    _dash_paint

    # El subshell no hereda los traps para que un `exit` dentro del comando no
    # imprima el resumen ni salga del buffer alterno.
    ( trap - EXIT INT TERM; "$@" ) >"$DASH_OUT_LOG" 2>"$DASH_ERR_LOG" </dev/null &
    local pid=$!
    DASH_BG_PID=$pid
    while kill -0 "$pid" 2>/dev/null; do
        DASH_TICK=$(( DASH_TICK + 1 ))
        _dash_paint
        sleep 0.12
    done
    local rc=0
    wait "$pid" || rc=$?
    DASH_BG_PID=""

    {
        cat "$DASH_OUT_LOG"
        cat "$DASH_ERR_LOG"
    } >>"$DASH_SESSION_LOG" 2>/dev/null || true

    DASH_TICK=$(( DASH_TICK + 1 ))
    _dash_paint
    return "$rc"
}

dash_run_as() {
    # dash_run_as "comando visible" "descripción" comando [args...]
    # Para comandos compuestos (funciones): muestra el comando real en el panel
    # en lugar del nombre de la función.
    local show="$1" label="$2"
    shift 2
    DASH_CMD_OVERRIDE="$show"
    dash_run "$label" "$@"
}

dash_capture() {
    # dash_capture <variable> "descripción" comando [args...]
    # Igual que dash_run pero deja el stdout en la variable indicada.
    local var="$1" label="$2"
    shift 2
    local out rc=0

    if [[ "$DASH_MODE" != "full" ]]; then
        if [[ -n "${DASH_CMD_OVERRIDE:-}" ]]; then
            printf '  $ %s\n' "$DASH_CMD_OVERRIDE"
            DASH_CMD_OVERRIDE=""
        else
            printf '  $ %s\n' "$*"
        fi
        out="$("$@" 2>/dev/null)" || rc=$?
        printf -v "$var" '%s' "$out"
        return "$rc"
    fi

    dash_run "$label" "$@" || rc=$?
    out="$(cat "$DASH_OUT_LOG" 2>/dev/null)"
    printf -v "$var" '%s' "$out"
    return "$rc"
}

dash_capture_as() {
    # dash_capture_as <variable> "comando visible" "descripción" comando [args...]
    local var="$1" show="$2" label="$3"
    shift 3
    DASH_CMD_OVERRIDE="$show"
    dash_capture "$var" "$label" "$@"
}

dash_fail() {
    DASH_OK=0
    DASH_ERROR="$*"
    if [[ "${DASH_ACTIVE:-0}" == "1" ]]; then
        local i
        for (( i = DASH_N - 1; i >= 0; i-- )); do
            if [[ "${DASH_STATES[$i]}" == "running" ]]; then
                DASH_STATES[$i]="failed"
                DASH_STOPPED[$i]=$SECONDS
                break
            fi
        done
        if [[ "$DASH_MODE" != "full" ]]; then
            printf 'ERROR: %s\n' "$*" >&2
        fi
    else
        printf 'ERROR: %s\n' "$*" >&2
    fi
    exit 1
}

dash_finish() {
    # dash_finish ok|fail [mensaje]
    local result="${1:-ok}"
    DASH_FINISH_MSG="${2:-}"
    if [[ "$result" == "ok" ]]; then
        DASH_OK=1
        local i
        for (( i = 0; i < DASH_N; i++ )); do
            if [[ "${DASH_STATES[$i]}" == "running" ]]; then
                DASH_STATES[$i]="done"
                DASH_STOPPED[$i]=$SECONDS
            fi
        done
        DASH_CMD=""
        [[ -n "$DASH_FINISH_MSG" ]] && DASH_ACTIVITY="$DASH_FINISH_MSG"
        if [[ "$DASH_MODE" == "full" ]]; then
            DASH_TICK=$(( DASH_TICK + 1 ))
            _dash_paint
        fi
        exit 0
    fi
    DASH_OK=0
    DASH_ERROR="${DASH_ERROR:-$DASH_FINISH_MSG}"
    exit 1
}
