<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Supervisório LTR701 · Planta Real & Sombra Digital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <style>
        body { background-color: #0b1120; }
        .panel-card { background-color: #111827; border: 1px solid rgba(148, 163, 184, 0.16); }

        .mode-banner-real {
            background: linear-gradient(90deg, rgba(16, 185, 129, 0.18), rgba(6, 78, 59, 0.25));
            border: 1px solid rgba(16, 185, 129, 0.45);
        }
        .mode-banner-sim {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.22), rgba(76, 29, 149, 0.25));
            border: 1px solid rgba(167, 139, 250, 0.5);
        }

        /* Largura fixa tabular: até 4 inteiros + '.' + 3 decimais sem trepidação lateral */
        .num-fixed {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-variant-numeric: tabular-nums;
            display: inline-block;
            min-width: 8.5ch;
            text-align: right;
            white-space: nowrap;
        }
        .num-fixed-sm {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-variant-numeric: tabular-nums;
            display: inline-block;
            min-width: 7.5ch;
            text-align: right;
            white-space: nowrap;
        }

        .kpi-value { font-size: 1.5rem; font-weight: 700; line-height: 1.15; }
        .chart-box-lg { position: relative; height: 300px; width: 100%; }
        .chart-box    { position: relative; height: 255px; width: 100%; }
        .chart-box-sm { position: relative; height: 180px; width: 100%; }
        .serial-console {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.78rem;
            background: #020617;
            color: #86efac;
            height: 145px;
            overflow-y: auto;
        }

        /* Blocos interativos do SVG */
        .svg-block { cursor: pointer; transition: filter 0.15s ease, opacity 0.15s ease; }
        .svg-block:hover { filter: brightness(1.18); }

        .status-dot {
            display: inline-block;
            width: 9px;
            height: 9px;
            border-radius: 50%;
        }
        code { color: #38bdf8; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="ltr701App()" x-init="init()">

{{-- Navbar Superior --}}
<nav class="navbar navbar-expand-lg border-bottom border-secondary-subtle bg-dark px-3 py-2">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-warning fw-semibold">LTR701 · SCOM / LCON-I</span>
            <span class="navbar-brand mb-0 h6 fw-bold">Supervisório Acoplado</span>
        </div>

        <ul class="nav nav-pills mx-auto my-2 my-lg-0 bg-body-tertiary rounded-3 p-1">
            <li class="nav-item">
                <button type="button" class="nav-link py-1 px-3 small fw-semibold d-flex align-items-center gap-2"
                        :class="activeTab === 'real' && 'active bg-success text-white'"
                        @click="switchTab('real')">
                    <span class="status-dot" :class="isBoardOnline() ? 'bg-light' : 'bg-warning'"></span>
                    Planta Real (Hardware)
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link py-1 px-3 small fw-semibold d-flex align-items-center gap-2"
                        :class="activeTab === 'sim' && 'active'"
                        :style="activeTab === 'sim' ? 'background-color:#7c3aed;color:#fff;' : ''"
                        @click="switchTab('sim')">
                    <span class="status-dot" :class="simRunning ? 'bg-info' : 'bg-secondary'"></span>
                    Sombra Digital (Simulação Clonada)
                </button>
            </li>
        </ul>

        <div class="d-flex align-items-center gap-3">
            <div class="text-end d-none d-md-block">
                <div class="small fw-semibold lh-1">{{ auth()->user()->name }}</div>
                <span class="badge {{ auth()->user()->role === 'admin' ? 'text-bg-danger' : 'text-bg-info' }}" style="font-size: 0.65rem;">
                    {{ strtoupper(auth()->user()->role) }}
                </span>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="m-0">
                @csrf
                <button type="submit" class="btn btn-outline-light btn-sm">Sair</button>
            </form>
        </div>
    </div>
</nav>

<main class="container-fluid py-3 px-3 px-lg-4">

    <template x-if="feedback.text">
        <div class="alert py-2 small d-flex justify-content-between align-items-center mb-3"
             :class="feedback.type === 'error' ? 'alert-danger' : 'alert-success'">
            <span x-text="feedback.text"></span>
            <button type="button" class="btn-close btn-sm" @click="feedback.text = ''"></button>
        </div>
    </template>

    {{-- ===================================================================== --}}
    {{-- ABA 1: PLANTA REAL (HARDWARE + SELETOR DE PLACA ESTILO ARDUINO IDE)   --}}
    {{-- ===================================================================== --}}
    <div x-show="activeTab === 'real'">

        {{-- Faixa de Identificação + Seletor de Placa e Porta (Arduino IDE Style) --}}
        <div class="rounded-3 p-3 mb-3 mode-banner-real">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-success fw-bold">MODO HARDWARE REAL</span>
                        <span class="small text-light fw-semibold" x-text="boardStatusText()"></span>
                    </div>
                    <div class="small text-secondary mt-1">
                        Comandos dos sliders vão direto para a placa selecionada (USB Serial ou API Wi-Fi) e são gravados criptografados no MySQL.
                    </div>
                </div>

                {{-- Seletor de Placa e Porta estilo Arduino IDE --}}
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="input-group input-group-sm" style="min-width: 310px;">
                        <span class="input-group-text bg-dark border-success text-success fw-semibold">Placa / Porta</span>
                        <select class="form-select bg-dark border-success text-light"
                                x-model="selectedBoardKey"
                                @change="onBoardChange()">
                            <option value="ALL">Todas as placas Wi-Fi (Auto)</option>

                            <optgroup label="── Portas USB (Web Serial · 115200 baud) ──">
                                <template x-if="usb.connected">
                                    <option :value="'usb:' + usb.deviceId" x-text="'● ' + usb.label + ' [' + usb.deviceId + '] (CONECTADA)'"></option>
                                </template>
                                <template x-if="!usb.connected">
                                    <option disabled value="">Nenhuma placa USB aberta — clique em Detectar USB</option>
                                </template>
                            </optgroup>

                            <optgroup label="── Placas na Rede (Wi-Fi / REST API) ──">
                                <template x-if="wifiDevices.length === 0">
                                    <option disabled value="">Nenhuma placa Wi-Fi detectada ainda</option>
                                </template>
                                <template x-for="dev in wifiDevices" :key="dev.device_id">
                                    <option :value="'wifi:' + dev.device_id"
                                            x-text="(dev.online ? '● ' : '○ ') + dev.device_id + ' · ' + dev.ip + (dev.online ? ' (ONLINE)' : ' (offline há ' + dev.age_sec + 's)')">
                                    </option>
                                </template>
                            </optgroup>
                        </select>
                    </div>

                    <button type="button" class="btn btn-sm fw-semibold"
                            :class="usb.connected ? 'btn-outline-danger' : 'btn-success'"
                            @click="usb.connected ? disconnectUsb() : connectUsb()"
                            x-text="usb.connected ? 'Desconectar USB' : '+ Detectar Placa USB'"></button>

                    <button type="button" class="btn btn-outline-light btn-sm" @click="fetchRealHistory(false)">
                        Atualizar Portas
                    </button>

                    <button type="button" class="btn btn-outline-info btn-sm"
                            x-show="usb.connected"
                            @click="usb.showMonitor = !usb.showMonitor"
                            x-text="usb.showMonitor ? 'Ocultar Monitor Serial' : 'Monitor Serial'"></button>

                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="exportCsv(realReadings, 'planta_real_ltr701.csv')"
                            :disabled="realReadings.length === 0">CSV Real</button>
                </div>
            </div>

            {{-- Monitor Serial estilo Arduino IDE (quando placa USB está ativa) --}}
            <div class="mt-3" x-show="usb.connected && usb.showMonitor" x-cloak>
                <div class="serial-console rounded-2 p-2 border border-success-subtle" id="serialConsoleBox">
                    <template x-if="usb.logs.length === 0">
                        <div class="text-secondary">Aguardando dados da porta serial (115200 baud)... Aceita JSON {"flow":50,"temp":35} ou flow=50,temp=35</div>
                    </template>
                    <template x-for="(line, idx) in usb.logs" :key="idx">
                        <div x-text="line"></div>
                    </template>
                </div>
                <div class="input-group input-group-sm mt-2">
                    <span class="input-group-text bg-dark text-success">TX Serial</span>
                    <input type="text" class="form-control font-monospace" x-model="usb.manualCmd"
                           @keydown.enter="sendManualUsb()" placeholder="Ex.: setpoint_temp=40.0 ou enable_flow=1">
                    <button type="button" class="btn btn-success" @click="sendManualUsb()">Enviar</button>
                    <button type="button" class="btn btn-outline-secondary" @click="usb.logs = []">Limpar</button>
                </div>
            </div>
        </div>

        {{-- 6 KPIs (Atualização fixa em 1 Hz, 3 casas decimais) --}}
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Vazão Mássica (S5)</div>
                        <div class="kpi-value text-info">
                            <span class="num-fixed" x-text="fmt(realDisplay.flow, 3)"></span>
                            <span class="fs-6 text-secondary">kg/h</span>
                        </div>
                        <div class="small text-secondary">
                            SP: <span class="num-fixed-sm text-warning" x-text="fmt(realState.setpoint_flow, 3)"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Temperatura (S2/S3)</div>
                        <div class="kpi-value text-warning">
                            <span class="num-fixed" x-text="fmt(realDisplay.temp, 3)"></span>
                            <span class="fs-6 text-secondary">°C</span>
                        </div>
                        <div class="small text-secondary">
                            SP: <span class="num-fixed-sm text-warning" x-text="fmt(realState.setpoint_temp, 3)"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Velocidade Real do Ar</div>
                        <div class="kpi-value text-success">
                            <span class="num-fixed" x-text="fmt(realDisplay.velocity, 3)"></span>
                            <span class="fs-6 text-secondary">m/s</span>
                        </div>
                        <div class="small text-secondary">
                            ρ(T): <span class="num-fixed-sm text-light" x-text="fmt(realDisplay.density, 3)"></span> kg/m³
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Pressão Tubo (ΔP / Abs)</div>
                        <div class="kpi-value text-light">
                            <span class="num-fixed" x-text="fmt(realDisplay.pressurePa, 3)"></span>
                            <span class="fs-6 text-secondary">Pa</span>
                        </div>
                        <div class="small text-secondary">
                            Abs: <span class="num-fixed-sm text-info" x-text="fmt(realDisplay.pressureAtm, 3)"></span> atm
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Constantes de Tempo</div>
                        <div class="small mt-1">
                            τ Vazão: <span class="num-fixed-sm text-info fw-bold" x-text="fmt(realDisplay.tauFlow, 3)"></span> s
                        </div>
                        <div class="small mt-1">
                            τ Temp: <span class="num-fixed-sm text-warning fw-bold" x-text="fmt(realDisplay.tauTemp, 3)"></span> s
                        </div>
                        <div class="text-secondary" style="font-size: 0.72rem;">Dinâmica estimada</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Atuadores (0–10 V)</div>
                        <div class="small mt-1">
                            u₁ Vent: <span class="num-fixed-sm text-info fw-bold" x-text="fmt(realDisplay.uFlow, 3)"></span> V
                        </div>
                        <div class="small mt-1">
                            u₂ Aq.: <span class="num-fixed-sm text-warning fw-bold" x-text="fmt(realDisplay.uTemp, 3)"></span> V
                        </div>
                        <div class="text-secondary text-truncate" style="font-size: 0.72rem;">
                            ACK pend.: <strong x-text="realState.pending_commands"></strong> · <code x-text="realDisplay.device || '---'"></code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Controles Reais Diretos via Sliders --}}
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100 border-success-subtle">
                    <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold text-info small">Malha 1 Real · Vazão / Ventilador (ACTUATOR 1 · SENSOR 5)</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn fw-semibold"
                                    :class="realState.enable_flow ? 'btn-success' : 'btn-outline-success'"
                                    @click="sendRealCommand('enable_flow', 1, true)">LIGAR</button>
                            <button type="button" class="btn fw-semibold"
                                    :class="!realState.enable_flow ? 'btn-danger' : 'btn-outline-danger'"
                                    @click="sendRealCommand('enable_flow', 0, true)">DESLIGAR</button>
                        </div>
                    </div>
                    <div class="card-body py-2 px-3 d-flex flex-column gap-2">
                        <div>
                            <div class="d-flex justify-content-between align-items-center small">
                                <span class="text-secondary">Setpoint Vazão (envio direto)</span>
                                <span class="text-info fw-bold"><span class="num-fixed-sm" x-text="fmt(realForm.spFlow, 3)"></span> kg/h</span>
                            </div>
                            <input type="range" class="form-range" min="0" max="110" step="0.5"
                                   x-model.number="realForm.spFlow"
                                   @input="queueRealSlider('setpoint_flow', realForm.spFlow)">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Kp Vazão</span>
                                    <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(realForm.kpFlow, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="1.5" step="0.005"
                                       x-model.number="realForm.kpFlow"
                                       @input="queueRealSlider('kp_flow', realForm.kpFlow)">
                            </div>
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Ki Vazão</span>
                                    <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(realForm.kiFlow, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="2.0" step="0.005"
                                       x-model.number="realForm.kiFlow"
                                       @input="queueRealSlider('ki_flow', realForm.kiFlow)">
                            </div>
                        </div>
                        <div class="input-group input-group-sm mt-1">
                            <span class="input-group-text small">Calib. S5 f(x)</span>
                            <input type="text" class="form-control font-monospace" x-model="realForm.calibFlow">
                            <button type="button" class="btn btn-outline-info" @click="sendRealCommand('calib_flow', realForm.calibFlow, true)">Salvar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100 border-success-subtle">
                    <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold text-warning small">Malha 2 Real · Temperatura / Aquecedor (ACTUATOR 2 · SENSOR 2/3)</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn fw-semibold"
                                    :class="realState.enable_temp ? 'btn-success' : 'btn-outline-success'"
                                    @click="sendRealCommand('enable_temp', 1, true)">LIGAR</button>
                            <button type="button" class="btn fw-semibold"
                                    :class="!realState.enable_temp ? 'btn-danger' : 'btn-outline-danger'"
                                    @click="sendRealCommand('enable_temp', 0, true)">DESLIGAR</button>
                        </div>
                    </div>
                    <div class="card-body py-2 px-3 d-flex flex-column gap-2">
                        <div>
                            <div class="d-flex justify-content-between align-items-center small">
                                <span class="text-secondary">Setpoint Temperatura (envio direto)</span>
                                <span class="text-warning fw-bold"><span class="num-fixed-sm" x-text="fmt(realForm.spTemp, 3)"></span> °C</span>
                            </div>
                            <input type="range" class="form-range" min="20" max="85" step="0.5"
                                   x-model.number="realForm.spTemp"
                                   @input="queueRealSlider('setpoint_temp', realForm.spTemp)">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Kp Temp.</span>
                                    <span class="text-warning fw-semibold num-fixed-sm" x-text="fmt(realForm.kpTemp, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="2.5" step="0.01"
                                       x-model.number="realForm.kpTemp"
                                       @input="queueRealSlider('kp_temp', realForm.kpTemp)">
                            </div>
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Ki Temp.</span>
                                    <span class="text-warning fw-semibold num-fixed-sm" x-text="fmt(realForm.kiTemp, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="2.0" step="0.01"
                                       x-model.number="realForm.kiTemp"
                                       @input="queueRealSlider('ki_temp', realForm.kiTemp)">
                            </div>
                        </div>
                        <div class="input-group input-group-sm mt-1">
                            <span class="input-group-text small">Calib. S2 f(x)</span>
                            <input type="text" class="form-control font-monospace" x-model="realForm.calibTemp">
                            <button type="button" class="btn btn-outline-warning" @click="sendRealCommand('calib_temp', realForm.calibTemp, true)">Salvar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 3 Gráficos da Planta Real --}}
        <div class="card panel-card rounded-3 mb-3">
            <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                <span class="fw-semibold small text-success">Gráfico Unificado Real · Vazão × Temperatura × u₁ / u₂ (2 Hz)</span>
                <span class="small text-secondary" x-show="realReadings.length === 0">Conecte o ESP32 (USB/Wi-Fi) ou rode cliente.py para plotar dados reais</span>
            </div>
            <div class="card-body py-2"><div class="chart-box-lg"><canvas id="realUnifiedChart"></canvas></div></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold text-info">
                        Malha Real · Vazão (kg/h) & Controlador u₁ (V)
                    </div>
                    <div class="card-body py-2"><div class="chart-box"><canvas id="realFlowChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold text-warning">
                        Malha Real · Temperatura (°C) & Controlador u₂ (V)
                    </div>
                    <div class="card-body py-2"><div class="chart-box"><canvas id="realTempChart"></canvas></div></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================== --}}
    {{-- ABA 2: SOMBRA DIGITAL (CLONE EXATO DA ABA REAL — 100% ISOLADO)        --}}
    {{-- ===================================================================== --}}
    <div x-show="activeTab === 'sim'" x-cloak>

        {{-- Faixa Roxa de Identificação Clara da Sombra Digital --}}
        <div class="rounded-3 p-3 mb-3 mode-banner-sim">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge fw-bold" style="background-color:#7c3aed;">MODO SOMBRA DIGITAL · ISOLADO</span>
                        <span class="small text-light fw-semibold"
                              x-text="simRunning ? 'Simulação Acoplada Rodando (2 Hz Gráficos · 1 Hz Displays)' : 'Simulação Pausada'"></span>
                    </div>
                    <div class="small text-secondary mt-1">
                        Clone exato da aba real rodando localmente no navegador. Nenhuma alteração de SP, Kp, Ki ou planta é enviada ao ESP32 ou ao MySQL.
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm fw-semibold"
                            :class="simRunning ? 'btn-warning' : 'btn-info text-dark'"
                            @click="toggleSimEngine()"
                            x-text="simRunning ? 'Pausar Sombra Digital' : 'Iniciar Sombra Digital (2 Hz)'"></button>

                    <button type="button" class="btn btn-outline-warning btn-sm" @click="injectPerturbation('flow', -15)">
                        Perturb. Válvula (-15 kg/h)
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" @click="injectPerturbation('temp', -3)">
                        Perturb. Térmica (-3 °C)
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" @click="resetSimEngine()">
                        Resetar (22°C / 0 kg/h)
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="simDrawerOpen = !simDrawerOpen"
                            x-text="simDrawerOpen ? 'Ocultar Modelo G(s) & Ensaio' : '⚙ Configurar Planta G(s) & Ensaio'"></button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="exportCsv(simReadings, 'sombra_digital_ltr701.csv')"
                            :disabled="simReadings.length === 0">CSV Sim.</button>
                </div>
            </div>

            {{-- Gaveta Recolhível: Parâmetros da Planta + Ensaio em Degrau (LCON-I) --}}
            <div class="mt-3 pt-3 border-top border-secondary-subtle" x-show="simDrawerOpen" x-cloak>
                <div class="row g-3">
                    <div class="col-12 col-lg-5">
                        <div class="card panel-card rounded-3 h-100">
                            <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold">
                                Parâmetros Físicos da Sombra Digital (Tempo Real)
                            </div>
                            <div class="card-body py-2 small">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="text-secondary">Ganho Vazão K_q [(kg/h)/V]</label>
                                        <input type="number" step="0.1" class="form-control form-control-sm" x-model.number="simModel.kFlow">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Constante τ_vazão (s)</label>
                                        <input type="number" step="0.05" min="0.1" class="form-control form-control-sm" x-model.number="simModel.tauFlow">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Ganho Térmico K_t [°C/V]</label>
                                        <input type="number" step="0.1" class="form-control form-control-sm" x-model.number="simModel.kTemp">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Cap. Térmica Base τ_t0 (s)</label>
                                        <input type="number" step="0.1" min="0.2" class="form-control form-control-sm" x-model.number="simModel.cThBase">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Temp. Ambiente (°C)</label>
                                        <input type="number" step="0.5" class="form-control form-control-sm" x-model.number="simModel.tAmb">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Fator de Ruído (0 a 3x)</label>
                                        <input type="number" step="0.1" min="0" max="3" class="form-control form-control-sm" x-model.number="simModel.noiseScale">
                                    </div>
                                </div>
                                <div class="mt-2 pt-2 border-top border-secondary-subtle">
                                    <label class="text-secondary mb-1">Sintonizador Rápido (Roteiro 11 · ω_cg e pm → Vazão)</label>
                                    <div class="row g-2 align-items-center">
                                        <div class="col-5">
                                            <span class="text-secondary">ω_cg: <strong x-text="fmt(freqTune.wcg, 1)"></strong> rad/s</span>
                                            <input type="range" class="form-range" min="0.5" max="12" step="0.5" x-model.number="freqTune.wcg" @input="applyFreqTuning()">
                                        </div>
                                        <div class="col-5">
                                            <span class="text-secondary">pm: <strong x-text="freqTune.pm"></strong>°</span>
                                            <input type="range" class="form-range" min="20" max="95" step="1" x-model.number="freqTune.pm" @input="applyFreqTuning()">
                                        </div>
                                        <div class="col-2 text-end">
                                            <button type="button" class="btn btn-outline-info btn-sm" @click="applyFreqTuning()">OK</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Ensaio Determinístico em Degrau (Ativ. 11 / Projeto Integrador) --}}
                    <div class="col-12 col-lg-7">
                        <div class="card panel-card rounded-3 h-100">
                            <div class="card-header bg-transparent border-secondary-subtle py-2 d-flex justify-content-between align-items-center">
                                <span class="small fw-semibold">Ensaio Programado de Malha Fechada (Tustin 100 Hz · LCON-I)</span>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn" :class="stepTest.loop === 'flow' ? 'btn-info text-dark fw-semibold' : 'btn-outline-info'"
                                            @click="loadStepPreset('flow')">Vazão G_Q(s)</button>
                                    <button type="button" class="btn" :class="stepTest.loop === 'temp' ? 'btn-warning text-dark fw-semibold' : 'btn-outline-warning'"
                                            @click="loadStepPreset('temp')">Temp. G_T(s)</button>
                                </div>
                            </div>
                            <div class="card-body py-2 small">
                                <div class="row g-2 mb-2">
                                    <div class="col-2"><label class="text-secondary">t_f (s)</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.tFinal" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">y₀</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.y0" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">SP 1</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.sp1" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">SP 2</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.sp2" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">t_SP2</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.tSp2" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">Pert (t=<span x-text="stepTest.tPert"></span>s)</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.pert" @input="runStepTest()"></div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-12 col-md-7"><div class="chart-box-sm"><canvas id="stepRespChart"></canvas></div></div>
                                    <div class="col-12 col-md-5"><div class="chart-box-sm"><canvas id="stepCtrlChart"></canvas></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 6 KPIs Clonados (Atualização fixa em 1 Hz, 3 casas decimais) --}}
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Vazão Mássica (Sim)</div>
                        <div class="kpi-value text-info">
                            <span class="num-fixed" x-text="fmt(simDisplay.flow, 3)"></span>
                            <span class="fs-6 text-secondary">kg/h</span>
                        </div>
                        <div class="small text-secondary">
                            SP: <span class="num-fixed-sm text-warning" x-text="fmt(simState.setpoint_flow, 3)"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Temperatura (Sim)</div>
                        <div class="kpi-value text-warning">
                            <span class="num-fixed" x-text="fmt(simDisplay.temp, 3)"></span>
                            <span class="fs-6 text-secondary">°C</span>
                        </div>
                        <div class="small text-secondary">
                            SP: <span class="num-fixed-sm text-warning" x-text="fmt(simState.setpoint_temp, 3)"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Velocidade Real do Ar</div>
                        <div class="kpi-value text-success">
                            <span class="num-fixed" x-text="fmt(simDisplay.velocity, 3)"></span>
                            <span class="fs-6 text-secondary">m/s</span>
                        </div>
                        <div class="small text-secondary">
                            ρ(T): <span class="num-fixed-sm text-light" x-text="fmt(simDisplay.density, 3)"></span> kg/m³
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Pressão Tubo (ΔP / Abs)</div>
                        <div class="kpi-value text-light">
                            <span class="num-fixed" x-text="fmt(simDisplay.pressurePa, 3)"></span>
                            <span class="fs-6 text-secondary">Pa</span>
                        </div>
                        <div class="small text-secondary">
                            Abs: <span class="num-fixed-sm text-info" x-text="fmt(simDisplay.pressureAtm, 3)"></span> atm
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Constantes de Tempo</div>
                        <div class="small mt-1">
                            τ Vazão: <span class="num-fixed-sm text-info fw-bold" x-text="fmt(simDisplay.tauFlow, 3)"></span> s
                        </div>
                        <div class="small mt-1">
                            τ Temp: <span class="num-fixed-sm text-warning fw-bold" x-text="fmt(simDisplay.tauTemp, 3)"></span> s
                        </div>
                        <div class="text-secondary" style="font-size: 0.72rem;">τ_temp varia com vazão</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Atuadores (0–10 V)</div>
                        <div class="small mt-1">
                            u₁ Vent: <span class="num-fixed-sm text-info fw-bold" x-text="fmt(simDisplay.uFlow, 3)"></span> V
                        </div>
                        <div class="small mt-1">
                            u₂ Aq.: <span class="num-fixed-sm text-warning fw-bold" x-text="fmt(simDisplay.uTemp, 3)"></span> V
                        </div>
                        <div class="text-secondary" style="font-size: 0.72rem;">Fonte: <code>SOMBRA-LOCAL</code></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Controles Clonados (100% locais na Sombra Digital) --}}
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100" style="border-color: rgba(167,139,250,0.35);">
                    <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold text-info small">Malha 1 Simulada · Vazão / Ventilador (Isolado)</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn fw-semibold"
                                    :class="simState.enable_flow ? 'btn-success' : 'btn-outline-success'"
                                    @click="simState.enable_flow = true">LIGAR</button>
                            <button type="button" class="btn fw-semibold"
                                    :class="!simState.enable_flow ? 'btn-danger' : 'btn-outline-danger'"
                                    @click="simState.enable_flow = false; simPhys.iFlow = 0">DESLIGAR</button>
                        </div>
                    </div>
                    <div class="card-body py-2 px-3 d-flex flex-column gap-2">
                        <div>
                            <div class="d-flex justify-content-between align-items-center small">
                                <span class="text-secondary">Setpoint Vazão (simulado)</span>
                                <span class="text-info fw-bold"><span class="num-fixed-sm" x-text="fmt(simState.setpoint_flow, 3)"></span> kg/h</span>
                            </div>
                            <input type="range" class="form-range" min="0" max="110" step="0.5" x-model.number="simState.setpoint_flow">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Kp Vazão</span>
                                    <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(simState.kp_flow, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="1.5" step="0.005" x-model.number="simState.kp_flow" @input="runStepTest()">
                            </div>
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Ki Vazão</span>
                                    <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(simState.ki_flow, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="2.0" step="0.005" x-model.number="simState.ki_flow" @input="runStepTest()">
                            </div>
                        </div>
                        <div class="input-group input-group-sm mt-1">
                            <span class="input-group-text small">Calib. S5 f(x)</span>
                            <input type="text" class="form-control font-monospace" x-model="simState.calib_flow">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100" style="border-color: rgba(167,139,250,0.35);">
                    <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold text-warning small">Malha 2 Simulada · Temperatura / Aquecedor (Isolado)</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn fw-semibold"
                                    :class="simState.enable_temp ? 'btn-success' : 'btn-outline-success'"
                                    @click="simState.enable_temp = true">LIGAR</button>
                            <button type="button" class="btn fw-semibold"
                                    :class="!simState.enable_temp ? 'btn-danger' : 'btn-outline-danger'"
                                    @click="simState.enable_temp = false; simPhys.iTemp = 0">DESLIGAR</button>
                        </div>
                    </div>
                    <div class="card-body py-2 px-3 d-flex flex-column gap-2">
                        <div>
                            <div class="d-flex justify-content-between align-items-center small">
                                <span class="text-secondary">Setpoint Temperatura (simulado)</span>
                                <span class="text-warning fw-bold"><span class="num-fixed-sm" x-text="fmt(simState.setpoint_temp, 3)"></span> °C</span>
                            </div>
                            <input type="range" class="form-range" min="20" max="85" step="0.5" x-model.number="simState.setpoint_temp">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Kp Temp.</span>
                                    <span class="text-warning fw-semibold num-fixed-sm" x-text="fmt(simState.kp_temp, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="2.5" step="0.01" x-model.number="simState.kp_temp" @input="runStepTest()">
                            </div>
                            <div class="col-6">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-secondary">Ki Temp.</span>
                                    <span class="text-warning fw-semibold num-fixed-sm" x-text="fmt(simState.ki_temp, 3)"></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="2.0" step="0.01" x-model.number="simState.ki_temp" @input="runStepTest()">
                            </div>
                        </div>
                        <div class="input-group input-group-sm mt-1">
                            <span class="input-group-text small">Calib. S2 f(x)</span>
                            <input type="text" class="form-control font-monospace" x-model="simState.calib_temp">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 3 Gráficos Clonados da Sombra Digital --}}
        <div class="card panel-card rounded-3 mb-3">
            <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold" style="color:#c4b5fd;">
                Gráfico Unificado · Sombra Digital Acoplada (Vazão × Temperatura × u₁ / u₂ · 2 Hz)
            </div>
            <div class="card-body py-2"><div class="chart-box-lg"><canvas id="simUnifiedChart"></canvas></div></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold text-info">
                        Malha Simulada · Vazão (kg/h) & Controlador u₁ (V)
                    </div>
                    <div class="card-body py-2"><div class="chart-box"><canvas id="simFlowChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold text-warning">
                        Malha Simulada · Temperatura (°C) & Controlador u₂ (V)
                    </div>
                    <div class="card-body py-2"><div class="chart-box"><canvas id="simTempChart"></canvas></div></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================== --}}
    {{-- DIAGRAMA DE BLOCOS SVG INTERATIVO (COMPARTILHADO — REFLETE ABA ATIVA) --}}
    {{-- ===================================================================== --}}
    <div class="card panel-card rounded-3 mb-3">
        <div class="card-header bg-transparent border-secondary-subtle d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold small">Diagrama de Blocos Interativo da Malha Fechada (LCON-I · Clique nos blocos)</span>
                <span class="badge" :class="activeTab === 'real' ? 'text-bg-success' : 'text-bg-primary'"
                      x-text="activeTab === 'real' ? 'Valores: Planta Real' : 'Valores: Sombra Digital'"></span>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn" :class="diagramLoop === 'flow' ? 'btn-info text-dark fw-semibold' : 'btn-outline-info'"
                        @click="diagramLoop = 'flow'">Malha de Vazão (S5 / ACT 1)</button>
                <button type="button" class="btn" :class="diagramLoop === 'temp' ? 'btn-warning text-dark fw-semibold' : 'btn-outline-warning'"
                        @click="diagramLoop = 'temp'">Malha de Temperatura (S2 / ACT 2)</button>
            </div>
        </div>
        <div class="card-body py-3">
            <div class="w-100 overflow-auto">
                <svg viewBox="0 0 1040 255" class="w-100" style="min-width: 820px; max-height: 265px;">
                    <defs>
                        <marker id="arrowBlue" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 1 L 10 5 L 0 9 z" fill="#38bdf8"/>
                        </marker>
                        <marker id="arrowWarn" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 1 L 10 5 L 0 9 z" fill="#f59e0b"/>
                        </marker>
                    </defs>

                    {{-- Envelopes de domínio (Digital vs Físico) --}}
                    <rect x="12" y="12" width="435" height="232" rx="10" fill="rgba(56,189,248,0.04)" stroke="#38bdf8" stroke-dasharray="6,4" stroke-opacity="0.45"/>
                    <text x="24" y="30" fill="#7dd3fc" font-size="11" font-weight="600"
                          x-text="activeTab === 'real' ? 'DOMÍNIO DIGITAL · ESP32 / MATLAB + LARAVEL' : 'DOMÍNIO DIGITAL · SOMBRA DIGITAL (100 Hz)'"></text>

                    <rect x="655" y="12" width="372" height="232" rx="10" fill="rgba(251,146,60,0.04)" stroke="#fb923c" stroke-dasharray="6,4" stroke-opacity="0.45"/>
                    <text x="668" y="30" fill="#fdba74" font-size="11" font-weight="600">PLANTA FÍSICA · KIT ELWE LTR701</text>

                    {{-- Entrada Setpoint --}}
                    <g class="svg-block" @click="selectedBlock = 'sp'">
                        <rect x="22" y="68" width="96" height="52" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'sp' ? '#facc15' : '#475569'" stroke-width="2"/>
                        <text x="70" y="88" text-anchor="middle" fill="#94a3b8" font-size="10">SETPOINT (SP)</text>
                        <text x="70" y="108" text-anchor="middle" fill="#facc15" font-size="13" font-weight="700" font-family="monospace"
                              x-text="fmt(diag().sp, 2) + ' ' + diag().unit"></text>
                    </g>

                    {{-- Seta SP -> Somador --}}
                    <line x1="118" y1="94" x2="152" y2="94" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>

                    {{-- Somador --}}
                    <circle cx="172" cy="94" r="18" fill="#0f172a" stroke="#38bdf8" stroke-width="2"/>
                    <text x="163" y="92" fill="#e2e8f0" font-size="12" font-weight="700">+</text>
                    <text x="175" y="108" fill="#f87171" font-size="13" font-weight="700">−</text>

                    {{-- Seta Erro -> Controlador --}}
                    <line x1="190" y1="94" x2="246" y2="94" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>
                    <text x="218" y="84" text-anchor="middle" fill="#f87171" font-size="10" font-family="monospace"
                          x-text="'e=' + fmt(diag().err, 2)"></text>

                    {{-- Bloco Controlador PI Digital --}}
                    <g class="svg-block" @click="selectedBlock = 'pi'">
                        <rect x="250" y="56" width="182" height="76" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'pi' ? '#22c55e' : (diag().enabled ? '#38bdf8' : '#ef4444')" stroke-width="2.2"/>
                        <text x="341" y="75" text-anchor="middle" fill="#e2e8f0" font-size="11" font-weight="700">CONTROLADOR PI DIGITAL</text>
                        <text x="341" y="92" text-anchor="middle" fill="#94a3b8" font-size="10">u(k) = Kp·e(k) + i(k)  [Ts=10ms]</text>
                        <text x="341" y="110" text-anchor="middle" fill="#38bdf8" font-size="11" font-family="monospace"
                              x-text="'Kp=' + fmt(diag().kp, 3) + ' | Ki=' + fmt(diag().ki, 3)"></text>
                        <text x="341" y="125" text-anchor="middle" font-size="10" font-weight="700"
                              :fill="diag().enabled ? '#4ade80' : '#f87171'"
                              x-text="diag().enabled ? '● HABILITADO' : '○ DESLIGADO (i=0)'"></text>
                    </g>

                    {{-- Seta PI -> Condicionamento --}}
                    <line x1="432" y1="94" x2="478" y2="94" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>
                    <text x="455" y="84" text-anchor="middle" fill="#f59e0b" font-size="10" font-family="monospace"
                          x-text="fmt(diag().u, 2) + 'V'"></text>

                    {{-- Bloco Condicionamento de Sinais --}}
                    <g class="svg-block" @click="selectedBlock = 'cond'">
                        <rect x="482" y="56" width="156" height="148" rx="8" fill="#172554"
                              :stroke="selectedBlock === 'cond' ? '#38bdf8' : '#3b82f6'" stroke-width="2"/>
                        <text x="560" y="78" text-anchor="middle" fill="#bfdbfe" font-size="11" font-weight="700">CONDICIONAMENTO</text>
                        <text x="560" y="96" text-anchor="middle" fill="#93c5fd" font-size="10">Saída: GPIO25 → LM741</text>
                        <text x="560" y="111" text-anchor="middle" fill="#e2e8f0" font-size="10" font-family="monospace">0–3 V → 0–10 V</text>
                        <line x1="498" y1="128" x2="622" y2="128" stroke="#3b82f6" stroke-dasharray="3,3"/>
                        <text x="560" y="148" text-anchor="middle" fill="#93c5fd" font-size="10">Entrada: Divisor → GPIO34</text>
                        <text x="560" y="163" text-anchor="middle" fill="#e2e8f0" font-size="10" font-family="monospace">0–10 V → 0–3 V</text>
                        <text x="560" y="186" text-anchor="middle" fill="#94a3b8" font-size="9">(ou Placa NI 6003 direto)</text>
                    </g>

                    {{-- Seta Condicionamento -> Planta --}}
                    <line x1="638" y1="94" x2="682" y2="94" stroke="#f59e0b" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <text x="660" y="84" text-anchor="middle" fill="#fdba74" font-size="10">0–10 V</text>

                    {{-- Seta Perturbação (de cima para a Planta) --}}
                    <line x1="782" y1="36" x2="782" y2="54" stroke="#ef4444" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <text x="855" y="45" fill="#fca5a5" font-size="10"
                          x-text="diagramLoop === 'flow' ? 'Perturbação (Válvula manual)' : 'Perturbação (Vazão de ar)'"></text>

                    {{-- Bloco Planta Física G(s) --}}
                    <g class="svg-block" @click="selectedBlock = 'plant'">
                        <rect x="686" y="56" width="192" height="76" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'plant' ? '#fb923c' : '#ea580c'" stroke-width="2.2"/>
                        <text x="782" y="75" text-anchor="middle" fill="#fdba74" font-size="11" font-weight="700"
                              x-text="diagramLoop === 'flow' ? 'ACTUATOR 1 + DUTO' : 'ACTUATOR 2 + DUTO'"></text>
                        <text x="782" y="94" text-anchor="middle" fill="#e2e8f0" font-size="10" font-family="monospace"
                              x-text="diagramLoop === 'flow' ? 'G(s) = 19.16 / (s²+4.564s+1.902)' : 'G(s) ≈ K_t / (τ(Q)·s + 1)'"></text>
                        <text x="782" y="114" text-anchor="middle" fill="#94a3b8" font-size="10" font-family="monospace"
                              x-text="'τ = ' + fmt(diag().tau, 3) + ' s'"></text>
                    </g>

                    {{-- Saída PV --}}
                    <line x1="878" y1="94" x2="914" y2="94" stroke="#f59e0b" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <g class="svg-block" @click="selectedBlock = 'plant'">
                        <rect x="918" y="68" width="98" height="52" rx="8" fill="#0f172a" stroke="#22c55e" stroke-width="2"/>
                        <text x="967" y="88" text-anchor="middle" fill="#86efac" font-size="10">SAÍDA (PV)</text>
                        <text x="967" y="108" text-anchor="middle" fill="#4ade80" font-size="13" font-weight="700" font-family="monospace"
                              x-text="fmt(diag().pv, 2) + ' ' + diag().unit"></text>
                    </g>

                    {{-- Realimentação inferior: PV -> Sensor -> Condicionamento -> Calibração -> Somador --}}
                    <polyline points="896,94 896,198 878,198" fill="none" stroke="#f59e0b" stroke-width="2"/>

                    <g class="svg-block" @click="selectedBlock = 'sensor'">
                        <rect x="686" y="168" width="192" height="58" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'sensor' ? '#a78bfa' : '#64748b'" stroke-width="2"/>
                        <text x="782" y="189" text-anchor="middle" fill="#e2e8f0" font-size="11" font-weight="700"
                              x-text="diagramLoop === 'flow' ? 'SENSOR 5 (Vazão Mássica)' : 'SENSOR 2/3 (Termopar K)'"></text>
                        <text x="782" y="209" text-anchor="middle" fill="#94a3b8" font-size="10">Saída analógica 0...10 V</text>
                    </g>

                    <line x1="686" y1="198" x2="640" y2="198" stroke="#f59e0b" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <line x1="482" y1="198" x2="434" y2="198" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>

                    {{-- Bloco Conversão + Filtro Passa-Baixas --}}
                    <g class="svg-block" @click="selectedBlock = 'sensor'">
                        <rect x="206" y="168" width="226" height="58" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'sensor' ? '#a78bfa' : '#38bdf8'" stroke-width="2"/>
                        <text x="319" y="187" text-anchor="middle" fill="#c4b5fd" font-size="10.5" font-weight="700">
                            CALIBRAÇÃO f(x) + FILTRO 12,5 Hz
                        </text>
                        <text x="319" y="208" text-anchor="middle" fill="#38bdf8" font-size="10.5" font-family="monospace"
                              x-text="'y = ' + diag().calib"></text>
                    </g>

                    <polyline points="206,198 172,198 172,115" fill="none" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>
                </svg>
            </div>

            {{-- Inspetor Contextual do Bloco Clicado --}}
            <div class="mt-2 p-2 px-3 rounded-2 bg-dark border border-secondary-subtle small d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong class="text-info" x-text="blockInfo().title + ':'"></strong>
                    <span class="text-light ms-1" x-text="blockInfo().desc"></span>
                </div>
                <span class="badge text-bg-secondary" x-text="blockInfo().ref"></span>
            </div>
        </div>
    </div>

    {{-- Logs de Auditoria (Exclusivo Admin) --}}
    @if (auth()->user()->role === 'admin')
    <div class="card panel-card rounded-3">
        <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold text-danger-emphasis small">Logs de Auditoria Criptografados (Exclusivo Admin · Apenas Aba Real)</span>
            <button type="button" class="btn btn-outline-danger btn-sm" @click="fetchLogs()">Atualizar</button>
        </div>
        <div class="table-responsive" style="max-height: 200px;">
            <table class="table table-dark table-striped table-sm align-middle mb-0 small">
                <thead><tr><th>Data/Hora</th><th>Usuário</th><th>IP</th><th>Ação</th></tr></thead>
                <tbody>
                    <template x-for="log in logs" :key="log.id">
                        <tr>
                            <td class="text-nowrap text-secondary" x-text="log.ts"></td>
                            <td x-text="log.user"></td>
                            <td><code x-text="log.ip"></code></td>
                            <td x-text="log.action"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
    @endif

</main>

<script>
const USB_VENDORS = {
    0x10C4: 'ESP32 / Silicon Labs CP210x',
    0x1A86: 'ESP32 / WCH CH340',
    0x0403: 'FTDI USB-UART',
    0x303A: 'Espressif Native USB-JTAG',
    0x2341: 'Arduino USB'
};

function ltr701App() {
    let realUnifiedChart = null, realFlowChart = null, realTempChart = null;
    let simUnifiedChart = null, simFlowChart = null, simTempChart = null;
    let stepRespChart = null, stepCtrlChart = null;

    const isAdmin = @json(auth()->user()->role === 'admin');
    const csrf    = document.querySelector('meta[name="csrf-token"]').content;
    const debounceTimers = {};

    // Referências Web Serial (fora da reatividade do Alpine)
    let serialPort = null;
    let serialWriter = null;
    let serialReader = null;
    let serialStopFlag = false;

    return {
        activeTab: 'real',
        diagramLoop: 'flow',
        selectedBlock: 'pi',
        feedback: { text: '', type: 'success' },
        logs: [],

        // ---------- ESTADO DA ABA 1 (PLANTA REAL) ----------
        selectedBoardKey: 'ALL',
        wifiDevices: [],
        realReadings: [],
        latestReal: null,
        realDisplay: {
            flow: null, temp: null, velocity: null, pressurePa: null, pressureAtm: null,
            density: null, tauFlow: 0.964, tauTemp: 2.500, uFlow: null, uTemp: null, device: ''
        },
        realState: {
            enable_flow: false, setpoint_flow: 50, kp_flow: 0.166, ki_flow: 0.276, calib_flow: '3.94 * exp(0.617 * x)',
            enable_temp: false, setpoint_temp: 40, kp_temp: 0.800, ki_temp: 0.300, calib_temp: '10.4 * x + 0.0795',
            pending_commands: 0
        },
        realForm: {
            spFlow: 50, kpFlow: 0.166, kiFlow: 0.276, calibFlow: '3.94 * exp(0.617 * x)',
            spTemp: 40, kpTemp: 0.800, kiTemp: 0.300, calibTemp: '10.4 * x + 0.0795'
        },

        // Estado USB Serial (estilo Arduino IDE)
        usb: {
            supported: 'serial' in navigator,
            connected: false,
            deviceId: 'USB-ESP32',
            label: '',
            showMonitor: false,
            manualCmd: '',
            logs: [],
            lastPersistMs: 0
        },

        // ---------- ESTADO DA ABA 2 (SOMBRA DIGITAL — CLONE ISOLADO) ----------
        simRunning: false,
        simDrawerOpen: false,
        simReadings: [],
        latestSim: null,
        simTimer: null,
        simDisplay: {
            flow: 0, temp: 22, velocity: 0, pressurePa: 0, pressureAtm: 1.0,
            density: 1.196, tauFlow: 0.964, tauTemp: 8.333, uFlow: 0, uTemp: 0
        },
        simState: {
            enable_flow: true, setpoint_flow: 50, kp_flow: 0.166, ki_flow: 0.276, calib_flow: '3.94 * exp(0.617 * x)',
            enable_temp: true, setpoint_temp: 40, kp_temp: 0.800, ki_temp: 0.300, calib_temp: '10.4 * x + 0.0795'
        },
        simPhys: { flow: 0, temp: 22.0, iFlow: 0, iTemp: 0, uFlow: 0, uTemp: 0 },
        simModel: { kFlow: 9.778, tauFlow: 0.964, kTemp: 8.5, cThBase: 2.5, tAmb: 22.0, noiseScale: 1.0 },
        freqTune: { wcg: 3.0, pm: 80 },
        stepTest: {
            loop: 'flow', b0: 19.16, a2: 1.0, a1: 4.564, a0: 1.902,
            tFinal: 15, y0: 0, sp1: 50, sp2: 80, tSp2: 10, pert: 10, tPert: 5
        },

        fmt(val, dec = 3) {
            if (val === null || val === undefined || isNaN(Number(val))) return '----.---';
            return Number(val).toFixed(dec);
        },

        async init() {
            this.initCharts();
            await this.fetchRealHistory(true);
            this.syncDisplays();
            if (isAdmin) await this.fetchLogs();
            this.runStepTest();

            // Displays numéricos: atualização fixa em 1 Hz (1000 ms) para leitura estável
            setInterval(() => this.syncDisplays(), 1000);

            // Polling Wi-Fi Real em 2 Hz (500 ms) para fluidez nos gráficos
            setInterval(() => {
                if (this.activeTab === 'real' && !this.selectedBoardKey.startsWith('usb:')) {
                    this.fetchRealHistory(false);
                }
            }, 500);
        },

        syncDisplays() {
            if (this.latestReal) {
                const r = this.latestReal;
                this.realDisplay = {
                    flow: r.flow, temp: r.temp, velocity: r.velocity,
                    pressurePa: r.pressure_pa, pressureAtm: r.pressure_atm,
                    density: r.density, tauFlow: r.tau_flow, tauTemp: r.tau_temp,
                    uFlow: r.u_flow, uTemp: r.u_temp, device: r.device_id
                };
            }
            if (this.latestSim) {
                const s = this.latestSim;
                this.simDisplay = {
                    flow: s.flow, temp: s.temp, velocity: s.velocity,
                    pressurePa: s.pressure_pa, pressureAtm: s.pressure_atm,
                    density: s.density, tauFlow: s.tau_flow, tauTemp: s.tau_temp,
                    uFlow: s.u_flow, uTemp: s.u_temp
                };
            }
        },

        switchTab(tab) {
            this.activeTab = tab;
            this.$nextTick(() => {
                if (tab === 'real') {
                    realUnifiedChart?.resize(); realFlowChart?.resize(); realTempChart?.resize();
                } else {
                    simUnifiedChart?.resize(); simFlowChart?.resize(); simTempChart?.resize();
                    stepRespChart?.resize(); stepCtrlChart?.resize();
                }
            });
        },

        // ---------- DETECÇÃO E STATUS DE PLACAS (ARDUINO IDE STYLE) ----------
        isBoardOnline() {
            if (this.selectedBoardKey.startsWith('usb:')) return this.usb.connected;
            if (this.selectedBoardKey.startsWith('wifi:')) {
                const id = this.selectedBoardKey.slice(5);
                return this.wifiDevices.some(d => d.device_id === id && d.online);
            }
            return this.usb.connected || this.wifiDevices.some(d => d.online);
        },

        boardStatusText() {
            if (this.selectedBoardKey.startsWith('usb:') && this.usb.connected) {
                return `Conectado via USB · ${this.usb.label} (115200 baud)`;
            }
            if (this.selectedBoardKey.startsWith('wifi:')) {
                const id = this.selectedBoardKey.slice(5);
                const dev = this.wifiDevices.find(d => d.device_id === id);
                if (!dev) return `Placa ${id} selecionada`;
                return dev.online ? `Conectado via Wi-Fi · ${dev.device_id} (${dev.ip})` : `Placa ${dev.device_id} offline (último sinal há ${dev.age_sec}s)`;
            }
            const onlineCount = this.wifiDevices.filter(d => d.online).length + (this.usb.connected ? 1 : 0);
            return onlineCount > 0 ? `${onlineCount} placa(s) ativa(s) detectada(s)` : 'Nenhuma placa conectada — selecione USB ou inicie o ESP32 Wi-Fi';
        },

        activeDeviceParam() {
            if (this.selectedBoardKey.startsWith('wifi:')) return this.selectedBoardKey.slice(5);
            if (this.selectedBoardKey.startsWith('usb:')) return this.usb.deviceId;
            return 'ALL';
        },

        onBoardChange() {
            if (!this.selectedBoardKey.startsWith('usb:')) {
                this.fetchRealHistory(false);
            }
        },

        async connectUsb() {
            if (!this.usb.supported) {
                this.notify('Web Serial API disponível apenas no Google Chrome ou Microsoft Edge.', 'error');
                return;
            }
            try {
                serialPort = await navigator.serial.requestPort();
                await serialPort.open({ baudRate: 115200 });

                const info = serialPort.getInfo();
                const vid  = info.usbVendorId;
                const pid  = info.usbProductId;
                this.usb.label    = USB_VENDORS[vid] || (vid ? `Dispositivo USB (VID 0x${vid.toString(16).toUpperCase()})` : 'Porta Serial USB');
                this.usb.deviceId = vid ? `USB-${vid.toString(16).toUpperCase()}-${(pid || 0).toString(16).toUpperCase()}` : 'USB-ESP32';
                this.usb.connected = true;
                this.usb.showMonitor = true;
                this.selectedBoardKey = 'usb:' + this.usb.deviceId;

                const encoder = new TextEncoderStream();
                encoder.readable.pipeTo(serialPort.writable);
                serialWriter = encoder.writable.getWriter();

                this.notify(`Placa detectada e conectada: ${this.usb.label}`);
                this.readUsbLoop();
            } catch (err) {
                if (err.name !== 'NotFoundError') {
                    this.notify('Erro ao abrir porta USB: ' + err.message, 'error');
                }
            }
        },

        async disconnectUsb() {
            serialStopFlag = true;
            try {
                if (serialReader) { await serialReader.cancel(); serialReader = null; }
                if (serialWriter) { await serialWriter.close(); serialWriter = null; }
                if (serialPort) { await serialPort.close(); serialPort = null; }
            } catch (e) {}
            this.usb.connected = false;
            this.selectedBoardKey = 'ALL';
            this.notify('Porta USB desconectada com segurança.');
        },

        async readUsbLoop() {
            serialStopFlag = false;
            const decoder = new TextDecoderStream();
            serialPort.readable.pipeTo(decoder.writable);
            serialReader = decoder.readable.getReader();

            let buffer = '';
            try {
                while (!serialStopFlag) {
                    const { value, done } = await serialReader.read();
                    if (done) break;
                    buffer += value;
                    const lines = buffer.split(/\r?\n/);
                    buffer = lines.pop() || '';
                    for (const raw of lines) {
                        const line = raw.trim();
                        if (!line) continue;
                        this.usb.logs.push(`[RX ${new Date().toLocaleTimeString()}] ${line}`);
                        if (this.usb.logs.length > 80) this.usb.logs.shift();
                        this.parseUsbLine(line);
                    }
                }
            } catch (err) {
                if (!serialStopFlag) this.notify('Conexão USB encerrada.', 'error');
            } finally {
                this.usb.connected = false;
            }
        },

        parseUsbLine(line) {
            let flow = null, temp = null, uFlow = 0, uTemp = 0;

            if (line.startsWith('{') && line.endsWith('}')) {
                try {
                    const obj = JSON.parse(line);
                    const v = obj.variables || obj;
                    if (v.flow !== undefined) flow = Number(v.flow);
                    if (v.temp !== undefined) temp = Number(v.temp);
                    if (v.u_flow !== undefined) uFlow = Number(v.u_flow);
                    if (v.u_temp !== undefined) uTemp = Number(v.u_temp);
                } catch (e) { return; }
            } else {
                const mFlow = line.match(/(?:flow|vazao|q)\s*[=:]\s*([-+]?\d+(?:\.\d+)?)/i);
                const mTemp = line.match(/(?:temp|temperature|t1)\s*[=:]\s*([-+]?\d+(?:\.\d+)?)/i);
                const mU1   = line.match(/(?:u_flow|u1|pm)\s*[=:]\s*([-+]?\d+(?:\.\d+)?)/i);
                const mU2   = line.match(/(?:u_temp|u2|ph)\s*[=:]\s*([-+]?\d+(?:\.\d+)?)/i);
                if (mFlow) flow = Number(mFlow[1]);
                if (mTemp) temp = Number(mTemp[1]);
                if (mU1)   uFlow = Number(mU1[1]);
                if (mU2)   uTemp = Number(mU2[1]);
            }

            if (flow === null && temp === null) return;

            const phys = this.computeClientPhysics(flow ?? 0, temp ?? 22);
            const reading = {
                id: Date.now(),
                device_id: this.usb.deviceId,
                flow, temp, u_flow: uFlow, u_temp: uTemp,
                sp_flow: this.realState.setpoint_flow,
                sp_temp: this.realState.setpoint_temp,
                ...phys,
                ts: new Date().toLocaleTimeString('pt-BR', { hour12: false }),
                ts_full: new Date().toLocaleString('pt-BR')
            };

            this.realReadings.push(reading);
            if (this.realReadings.length > 80) this.realReadings.shift();
            this.latestReal = reading;
            this.updateChartSet(realUnifiedChart, realFlowChart, realTempChart, this.realReadings, this.realState);

            // Persiste no MySQL a cada 1 segundo para não saturar requisições
            const nowMs = Date.now();
            if (nowMs - this.usb.lastPersistMs >= 1000) {
                this.usb.lastPersistMs = nowMs;
                fetch('/panel/simulate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ source: 'usb', device_id: this.usb.deviceId, flow, temp, u_flow: uFlow, u_temp: uTemp })
                }).catch(() => {});
            }
        },

        async writeUsbCommand(text) {
            if (!serialWriter) return;
            await serialWriter.write(text + '\n');
            this.usb.logs.push(`[TX ${new Date().toLocaleTimeString()}] ${text}`);
            if (this.usb.logs.length > 80) this.usb.logs.shift();
        },

        async sendManualUsb() {
            const cmd = this.usb.manualCmd.trim();
            if (!cmd) return;
            await this.writeUsbCommand(cmd);
            this.usb.manualCmd = '';
        },

        // ---------- COMANDOS E HISTÓRICO DA PLANTA REAL ----------
        queueRealSlider(name, value) {
            if (name === 'setpoint_flow') this.realState.setpoint_flow = Number(value);
            if (name === 'setpoint_temp') this.realState.setpoint_temp = Number(value);
            if (name === 'kp_flow') this.realState.kp_flow = Number(value);
            if (name === 'ki_flow') this.realState.ki_flow = Number(value);
            if (name === 'kp_temp') this.realState.kp_temp = Number(value);
            if (name === 'ki_temp') this.realState.ki_temp = Number(value);

            clearTimeout(debounceTimers[name]);
            debounceTimers[name] = setTimeout(() => {
                this.sendRealCommand(name, Number(value), false);
            }, 280);
        },

        async sendRealCommand(name, value, showToast = false) {
            // Se uma placa USB estiver ativa, envia direto pela porta serial também
            if (this.usb.connected && this.selectedBoardKey.startsWith('usb:')) {
                await this.writeUsbCommand(`${name}=${value}`);
            }

            const res = await fetch('/panel/send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ name, value, device_id: this.activeDeviceParam() })
            });
            const data = await res.json();
            if (!res.ok) {
                this.notify(data.message || 'Erro ao enviar comando.', 'error');
                return;
            }
            this.realState = data.state;
            if (showToast) this.notify(`Comando real "${name}" enviado.`);
            if (isAdmin && showToast) this.fetchLogs();
        },

        async fetchRealHistory(syncForms = false) {
            try {
                const devParam = encodeURIComponent(this.activeDeviceParam());
                const res = await fetch(`/panel/history?limit=80&real_only=1&device_id=${devParam}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;
                const data = await res.json();
                this.wifiDevices  = data.devices || [];
                this.realReadings = data.readings || [];

                if (data.state) {
                    this.realState = data.state;
                    if (syncForms) {
                        this.realForm = {
                            spFlow: data.state.setpoint_flow, kpFlow: data.state.kp_flow, kiFlow: data.state.ki_flow, calibFlow: data.state.calib_flow,
                            spTemp: data.state.setpoint_temp, kpTemp: data.state.kp_temp, kiTemp: data.state.ki_temp, calibTemp: data.state.calib_temp
                        };
                    }
                }
                if (this.realReadings.length > 0) {
                    this.latestReal = this.realReadings[this.realReadings.length - 1];
                }
                this.updateChartSet(realUnifiedChart, realFlowChart, realTempChart, this.realReadings, this.realState);
            } catch (e) { console.error(e); }
        },

        // ---------- MOTOR FÍSICO ACOPLADO DA SOMBRA DIGITAL (100% LOCAL · 2 Hz) ----------
        toggleSimEngine() {
            if (this.simRunning) {
                clearInterval(this.simTimer);
                this.simTimer = null;
                this.simRunning = false;
            } else {
                this.simRunning = true;
                this.stepSimEngine();
                this.simTimer = setInterval(() => this.stepSimEngine(), 500); // 2 pts/s
            }
        },

        resetSimEngine() {
            this.simPhys = { flow: 0, temp: this.simModel.tAmb, iFlow: 0, iTemp: 0, uFlow: 0, uTemp: 0 };
            this.simReadings = [];
            this.stepSimEngine();
            this.syncDisplays();
        },

        injectPerturbation(kind, delta) {
            if (kind === 'flow') {
                this.simPhys.flow = Math.max(0, this.simPhys.flow + delta);
                this.notify(`Perturbação aplicada na Sombra Digital: ${delta} kg/h na vazão.`);
            } else {
                this.simPhys.temp = Math.max(15, this.simPhys.temp + delta);
                this.notify(`Perturbação aplicada na Sombra Digital: ${delta} °C na temperatura.`);
            }
        },

        applyFreqTuning() {
            const { wcg, pm } = this.freqTune;
            const b0 = 19.16, a2 = 1.0, a1 = 4.564, a0 = 1.902;
            const reD = a0 - a2 * wcg * wcg;
            const imD = a1 * wcg;
            const magG = b0 / Math.hypot(reD, imD);
            const phaseG = -Math.atan2(imD, reD);
            const kp = 1 / magG;
            const ki = -kp * wcg * Math.tan((pm * Math.PI / 180) - Math.PI - phaseG);
            if (kp > 0 && ki > 0 && isFinite(kp) && isFinite(ki)) {
                this.simState.kp_flow = Number(kp.toFixed(3));
                this.simState.ki_flow = Number(ki.toFixed(3));
                this.runStepTest();
            }
        },

        stepSimEngine() {
            const subSteps = 10;
            const dt = 0.05; // 10 * 50 ms = 500 ms (2 Hz)
            const m = this.simModel;
            const st = this.simState;
            let { flow: q, temp: t, iFlow, iTemp, uFlow, uTemp } = this.simPhys;

            for (let s = 0; s < subSteps; s++) {
                // Controlador PI Vazão
                if (st.enable_flow) {
                    const errF = st.setpoint_flow - q;
                    const iCand = iFlow + st.ki_flow * errF * dt;
                    const uRaw  = st.kp_flow * errF + iCand;
                    uFlow = Math.max(0, Math.min(9.5, uRaw));
                    if (uRaw === uFlow || (uFlow >= 9.5 && errF < 0) || (uFlow <= 0 && errF > 0)) iFlow = iCand;
                } else {
                    iFlow = 0; uFlow = 0;
                }

                // Controlador PI Temperatura
                if (st.enable_temp) {
                    const errT = st.setpoint_temp - t;
                    const iCand = iTemp + st.ki_temp * errT * dt;
                    const uRaw  = st.kp_temp * errT + iCand;
                    uTemp = Math.max(0, Math.min(9.5, uRaw));
                    if (uRaw === uTemp || (uTemp >= 9.5 && errT < 0) || (uTemp <= 0 && errT > 0)) iTemp = iCand;
                } else {
                    iTemp = 0; uTemp = 0;
                }

                // Acoplamento físico (densidade + convecção forçada)
                const rhoRatio = (m.tAmb + 273.15) / (Math.max(10, t) + 273.15);
                const qTarget  = m.kFlow * uFlow * Math.sqrt(rhoRatio);
                q = Math.max(0, q + ((qTarget - q) / Math.max(0.1, m.tauFlow)) * dt);

                const hEff = 0.30 + 0.70 * (q / 25.0);
                const dTdt = (m.kTemp * uTemp - hEff * (t - m.tAmb)) / Math.max(0.2, m.cThBase);
                t = Math.max(15, Math.min(115, t + dTdt * dt));
            }

            this.simPhys = { flow: q, temp: t, iFlow, iTemp, uFlow, uTemp };

            // Ruído realista (suave em ambiente, maior sob aquecimento/fluxo)
            const ns = Math.max(0, Number(m.noiseScale) || 0);
            const flowSigma = (q > 0.2 ? (0.045 + 0.0075 * q) : 0.012) * ns;
            const tempSigma = ((uTemp > 0.1 || t > m.tAmb + 1.5) ? (0.045 + 0.013 * uTemp + 0.0015 * q) : 0.032) * ns;

            const measFlow = Math.max(0, Number((q + this.gauss(flowSigma)).toFixed(3)));
            const measTemp = Number((t + this.gauss(tempSigma)).toFixed(3));
            const phys     = this.computeClientPhysics(measFlow, measTemp);

            const reading = {
                id: Date.now(),
                device_id: 'SOMBRA-LOCAL',
                flow: measFlow,
                temp: measTemp,
                u_flow: Number(uFlow.toFixed(3)),
                u_temp: Number(uTemp.toFixed(3)),
                sp_flow: st.setpoint_flow,
                sp_temp: st.setpoint_temp,
                ...phys,
                ts: new Date().toLocaleTimeString('pt-BR', { hour12: false }),
                ts_full: new Date().toLocaleString('pt-BR')
            };

            this.simReadings.push(reading);
            if (this.simReadings.length > 80) this.simReadings.shift();
            this.latestSim = reading;
            this.updateChartSet(simUnifiedChart, simFlowChart, simTempChart, this.simReadings, this.simState);
        },

        computeClientPhysics(flowKgh, tempC) {
            const pAtm = 101325.0, rAir = 287.05, area = 0.0019635, kLoss = 3.2;
            const tempK = Math.max(250, tempC + 273.15);
            const density = pAtm / (rAir * tempK);
            const velocity = ((Math.max(0, flowKgh) / 3600) / density) / area;
            const pressure_pa = 0.5 * density * velocity * velocity * (1 + kLoss);
            const pressure_atm = (pAtm + pressure_pa) / pAtm;
            const hEff = 0.30 + 0.70 * (Math.max(0, flowKgh) / 25.0);

            return {
                density: Number(density.toFixed(3)),
                velocity: Number(velocity.toFixed(3)),
                pressure_pa: Number(pressure_pa.toFixed(3)),
                pressure_atm: Number(pressure_atm.toFixed(4)),
                tau_flow: Number(this.simModel.tauFlow.toFixed(3)),
                tau_temp: Number((this.simModel.cThBase / hEff).toFixed(3))
            };
        },

        gauss(sigma) {
            if (sigma <= 0) return 0;
            const u1 = Math.max(1e-7, Math.random());
            const u2 = Math.random();
            const z  = Math.sqrt(-2 * Math.log(u1)) * Math.cos(2 * Math.PI * u2);
            return Math.max(-2.5 * sigma, Math.min(2.5 * sigma, z * sigma));
        },

        // ---------- ENSAIO PROGRAMADO EM DEGRAU (LCON-I · TUSTIN 100 Hz) ----------
        loadStepPreset(loop) {
            this.stepTest.loop = loop;
            if (loop === 'flow') {
                Object.assign(this.stepTest, { b0: 19.16, a2: 1.0, a1: 4.564, a0: 1.902, tFinal: 15, y0: 0, sp1: 50, sp2: 80, tSp2: 10, pert: 10, tPert: 5 });
            } else {
                Object.assign(this.stepTest, { b0: 2.0, a2: 0.0, a1: 1.0, a0: 0.4, tFinal: 30, y0: 25, sp1: 35, sp2: 30, tSp2: 20, pert: 2, tPert: 15 });
            }
            this.runStepTest();
        },

        runStepTest() {
            if (!stepRespChart || !stepCtrlChart) return;
            const st = this.stepTest;
            const Kp = st.loop === 'flow' ? this.simState.kp_flow : this.simState.kp_temp;
            const Ki = st.loop === 'flow' ? this.simState.ki_flow : this.simState.ki_temp;

            const dt = 0.01, c = 2 / dt;
            const N = Math.floor(Math.max(5, Math.min(120, st.tFinal)) / dt);
            const is2nd = Math.abs(st.a2) > 1e-9;

            const n0 = st.b0, n1 = is2nd ? 2 * st.b0 : st.b0, n2 = is2nd ? st.b0 : 0;
            const d0 = is2nd ? (st.a2 * c * c + st.a1 * c + st.a0) : (st.a1 * c + st.a0);
            const d1 = is2nd ? (2 * st.a0 - 2 * st.a2 * c * c) : (st.a0 - st.a1 * c);
            const d2 = is2nd ? (st.a2 * c * c - st.a1 * c + st.a0) : 0;

            let u = [0, 0, 0], y = [st.y0, st.y0, st.y0], I = 0;
            const labels = [], yOut = [], spOut = [], eOut = [], uOut = [];
            const pertStep = Math.round(st.tPert / dt);
            const sampleEvery = Math.max(5, Math.floor(N / 220));

            for (let i = 0; i <= N; i++) {
                const t = i * dt;
                const sp = t >= st.tSp2 ? st.sp2 : st.sp1;
                const e = sp - y[0];
                I += Ki * e * dt;
                const ctrl = Math.max(0, Math.min(10, Kp * e + I));

                u[2] = u[1]; u[1] = u[0]; u[0] = ctrl;
                y[2] = y[1]; y[1] = y[0];
                y[0] = is2nd
                    ? (n0 * u[0] + n1 * u[1] + n2 * u[2] - d1 * y[1] - d2 * y[2]) / d0
                    : (n0 * u[0] + n1 * u[1] - d1 * y[1]) / d0;

                if (i === pertStep && pertStep > 0) y[0] -= st.pert;

                if (i % sampleEvery === 0) {
                    labels.push(t.toFixed(1) + 's');
                    yOut.push(Number(y[0].toFixed(2)));
                    spOut.push(sp);
                    eOut.push(Number(e.toFixed(2)));
                    uOut.push(Number(ctrl.toFixed(2)));
                }
            }

            stepRespChart.data.labels = labels;
            stepRespChart.data.datasets[0].data = yOut;
            stepRespChart.data.datasets[1].data = spOut;
            stepRespChart.data.datasets[2].data = eOut;
            stepRespChart.update('none');

            stepCtrlChart.data.labels = labels;
            stepCtrlChart.data.datasets[0].data = uOut;
            stepCtrlChart.update('none');
        },

        // ---------- DADOS DO DIAGRAMA DE BLOCOS SVG INTERATIVO ----------
        diag() {
            const st  = this.activeTab === 'real' ? this.realState : this.simState;
            const dsp = this.activeTab === 'real' ? this.realDisplay : this.simDisplay;
            if (this.diagramLoop === 'flow') {
                const pv = dsp.flow ?? 0;
                return {
                    unit: 'kg/h', sp: st.setpoint_flow, pv, err: st.setpoint_flow - pv,
                    kp: st.kp_flow, ki: st.ki_flow, enabled: st.enable_flow,
                    u: dsp.uFlow ?? 0, tau: dsp.tauFlow ?? 0.964, calib: st.calib_flow
                };
            }
            const pv = dsp.temp ?? 22;
            return {
                unit: '°C', sp: st.setpoint_temp, pv, err: st.setpoint_temp - pv,
                kp: st.kp_temp, ki: st.ki_temp, enabled: st.enable_temp,
                u: dsp.uTemp ?? 0, tau: dsp.tauTemp ?? 2.5, calib: st.calib_temp
            };
        },

        blockInfo() {
            const d = this.diag();
            const map = {
                sp: {
                    title: 'Referência / Setpoint (SP)',
                    desc: `Alvo atual: ${this.fmt(d.sp, 3)} ${d.unit}. Se o valor medido não convergir para o SP, verifique a saturação em 0–10 V e a calibração do sensor.`,
                    ref: 'LCON-I · Malha Fechada'
                },
                pi: {
                    title: 'Controlador PI Digital (Euler / Tustin · 100 Hz)',
                    desc: `Kp = ${this.fmt(d.kp, 3)}, Ki = ${this.fmt(d.ki, 3)}, erro atual = ${this.fmt(d.err, 3)} ${d.unit}. Se oscilar entre 0% e 100%, divida Kp e Ki por 2 ou 10; ao desligar, zere a parcela integral i(k).`,
                    ref: 'Ativ. 08, 09 e 11'
                },
                cond: {
                    title: 'Condicionamento de Sinais (0–10 V ↔ 0–3 V)',
                    desc: 'Na saída do ESP32 (GPIO 25), o amplificador não-inversor LM741 amplia 3 V para 10 V (ganho 1 + Rf/R). Na entrada (GPIO 34), o potenciômetro divisor reduz 10 V para 3 V.',
                    ref: 'Ativ. 08 e 09 · Parte A'
                },
                plant: {
                    title: 'Planta Física · Kit ELWE LTR701',
                    desc: this.diagramLoop === 'flow'
                        ? 'Modelo 2ª ordem (fit 92,07%): G(s) = 19,16 / (s² + 4,564s + 1,902). Perturbação física via válvula manual na entrada.'
                        : 'Aquecedor (ACTUATOR 2) acoplado à vazão do ventilador (ACTUATOR 1). A constante de tempo térmica τ cai quando a vazão aumenta.',
                    ref: this.diagramLoop === 'flow' ? 'Ativ. 10 · Parte C' : 'Ativ. 07 · Parte C'
                },
                sensor: {
                    title: 'Sensor, Equação de Calibração f(x) & Filtro Digital',
                    desc: `Fórmula ativa: y = ${d.calib}. Na malha de temperatura aplica-se filtro passa-baixas digital (fc = 12,5 Hz) antes de calcular o erro. Divergência fixa em relação ao display do kit indica ajuste no divisor ou na fórmula f(x).`,
                    ref: this.diagramLoop === 'flow' ? 'Ativ. 10 · Parte A' : 'Ativ. 07 · Parte A'
                }
            };
            return map[this.selectedBlock] || map.pi;
        },

        // ---------- GRÁFICOS CHART.JS ----------
        initCharts() {
            const dualOpts = (leftTitle) => ({
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: 10 }, grid: { color: 'rgba(148,163,184,0.08)' } },
                    y: { position: 'left', title: { display: true, text: leftTitle, color: '#cbd5e1' }, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } },
                    y1: { position: 'right', min: 0, max: 10.5, title: { display: true, text: 'Controle [V]', color: '#94a3b8' }, ticks: { color: '#94a3b8' }, grid: { drawOnChartArea: false } }
                },
                plugins: { legend: { labels: { color: '#e2e8f0', boxWidth: 14 } } }
            });

            const createTriplet = (uId, fId, tId) => ([
                new Chart(document.getElementById(uId), {
                    type: 'line',
                    data: { labels: [], datasets: [
                        { label: 'Vazão (kg/h)', data: [], yAxisID: 'y', borderColor: '#38bdf8', borderWidth: 2.2, tension: 0.2, pointRadius: 0 },
                        { label: 'Temperatura (°C)', data: [], yAxisID: 'y', borderColor: '#fb923c', borderWidth: 2.2, tension: 0.2, pointRadius: 0 },
                        { label: 'u₁ Ventilador (V)', data: [], yAxisID: 'y1', borderColor: '#818cf8', borderDash: [4,3], borderWidth: 1.5, pointRadius: 0 },
                        { label: 'u₂ Aquecedor (V)', data: [], yAxisID: 'y1', borderColor: '#f43f5e', borderDash: [4,3], borderWidth: 1.5, pointRadius: 0 }
                    ]},
                    options: dualOpts('kg/h · °C')
                }),
                new Chart(document.getElementById(fId), {
                    type: 'line',
                    data: { labels: [], datasets: [
                        { label: 'Vazão (kg/h)', data: [], yAxisID: 'y', borderColor: '#38bdf8', backgroundColor: 'rgba(56,189,248,0.12)', fill: true, tension: 0.2, pointRadius: 0 },
                        { label: 'Setpoint', data: [], yAxisID: 'y', borderColor: '#facc15', borderDash: [6,4], fill: false, pointRadius: 0 },
                        { label: 'Controle u₁ (V)', data: [], yAxisID: 'y1', borderColor: '#a78bfa', borderWidth: 1.5, fill: false, pointRadius: 0 }
                    ]},
                    options: dualOpts('kg/h')
                }),
                new Chart(document.getElementById(tId), {
                    type: 'line',
                    data: { labels: [], datasets: [
                        { label: 'Temperatura (°C)', data: [], yAxisID: 'y', borderColor: '#fb923c', backgroundColor: 'rgba(251,146,60,0.12)', fill: true, tension: 0.2, pointRadius: 0 },
                        { label: 'Setpoint', data: [], yAxisID: 'y', borderColor: '#facc15', borderDash: [6,4], fill: false, pointRadius: 0 },
                        { label: 'Controle u₂ (V)', data: [], yAxisID: 'y1', borderColor: '#f43f5e', borderWidth: 1.5, fill: false, pointRadius: 0 }
                    ]},
                    options: dualOpts('°C')
                })
            ]);

            [realUnifiedChart, realFlowChart, realTempChart] = createTriplet('realUnifiedChart', 'realFlowChart', 'realTempChart');
            [simUnifiedChart, simFlowChart, simTempChart]    = createTriplet('simUnifiedChart', 'simFlowChart', 'simTempChart');

            const simpleOpts = (yTitle, maxY = null) => ({
                responsive: true, maintainAspectRatio: false, animation: false,
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: 8 }, grid: { color: 'rgba(148,163,184,0.08)' } },
                    y: { ...(maxY ? { min: 0, max: maxY } : {}), title: { display: true, text: yTitle, color: '#94a3b8' }, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } }
                },
                plugins: { legend: { labels: { color: '#e2e8f0', boxWidth: 12 } } }
            });

            stepRespChart = new Chart(document.getElementById('stepRespChart'), {
                type: 'line',
                data: { labels: [], datasets: [
                    { label: 'PV', data: [], borderColor: '#22c55e', borderWidth: 2, pointRadius: 0 },
                    { label: 'SP', data: [], borderColor: '#38bdf8', borderDash: [5,4], borderWidth: 1.6, pointRadius: 0 },
                    { label: 'Erro', data: [], borderColor: '#f87171', borderWidth: 1.1, pointRadius: 0 }
                ]},
                options: simpleOpts('Resposta')
            });

            stepCtrlChart = new Chart(document.getElementById('stepCtrlChart'), {
                type: 'line',
                data: { labels: [], datasets: [
                    { label: 'u(t) [V]', data: [], borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.14)', fill: true, borderWidth: 1.8, pointRadius: 0 }
                ]},
                options: simpleOpts('Tensão [V]', 10.5)
            });
        },

        updateChartSet(uChart, fChart, tChart, list, st) {
            const labels = list.map(r => r.ts);
            const flow   = list.map(r => r.flow);
            const temp   = list.map(r => r.temp);
            const uFlow  = list.map(r => r.u_flow);
            const uTemp  = list.map(r => r.u_temp);

            uChart.data.labels = labels;
            uChart.data.datasets[0].data = flow;
            uChart.data.datasets[1].data = temp;
            uChart.data.datasets[2].data = uFlow;
            uChart.data.datasets[3].data = uTemp;
            uChart.update('none');

            fChart.data.labels = labels;
            fChart.data.datasets[0].data = flow;
            fChart.data.datasets[1].data = list.map(r => r.sp_flow ?? st.setpoint_flow);
            fChart.data.datasets[2].data = uFlow;
            fChart.update('none');

            tChart.data.labels = labels;
            tChart.data.datasets[0].data = temp;
            tChart.data.datasets[1].data = list.map(r => r.sp_temp ?? st.setpoint_temp);
            tChart.data.datasets[2].data = uTemp;
            tChart.update('none');
        },

        async fetchLogs() {
            const res = await fetch('/panel/logs', { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            this.logs = data.logs || [];
        },

        exportCsv(list, filename) {
            const header = 'id,horario,dispositivo,vazao_kgh,temp_c,velocidade_ms,pressao_pa,pressao_atm,u_vazao_v,u_temp_v\n';
            const rows = list.map(r => [
                r.id, `"${r.ts_full || r.ts}"`, r.device_id,
                r.flow ?? '', r.temp ?? '', r.velocity ?? '', r.pressure_pa ?? '', r.pressure_atm ?? '',
                r.u_flow ?? '', r.u_temp ?? ''
            ].join(',')).join('\n');
            const blob = new Blob([header + rows], { type: 'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            a.click();
        },

        notify(text, type = 'success') {
            this.feedback = { text, type };
            setTimeout(() => { if (this.feedback.text === text) this.feedback.text = ''; }, 3500);
        }
    };
}
</script>
</body>
</html>