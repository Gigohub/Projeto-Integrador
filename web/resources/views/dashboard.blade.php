<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Supervisório LTR701 · Controle de Temperatura</title>
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

        .num-fixed {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-variant-numeric: tabular-nums;
            display: inline-block;
            min-width: 7.5ch;
            text-align: right;
            white-space: nowrap;
        }
        .num-fixed-sm {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-variant-numeric: tabular-nums;
            display: inline-block;
            min-width: 6.5ch;
            text-align: right;
            white-space: nowrap;
        }

        .kpi-value    { font-size: 1.55rem; font-weight: 700; line-height: 1.15; }
        .chart-box-lg { position: relative; height: 310px; width: 100%; }
        .chart-box    { position: relative; height: 245px; width: 100%; }
        .chart-box-sm { position: relative; height: 185px; width: 100%; }

        .svg-block { cursor: pointer; transition: filter 0.15s ease; }
        .svg-block:hover { filter: brightness(1.18); }

        .status-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; }
        code { color: #38bdf8; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="ltr701TempApp()" x-init="init()">

@php
    $userName = auth()->user()?->name ?? 'Operador Local (Tempo Real)';
    $userRole = auth()->user()?->role ?? 'admin';
    $isAdmin  = !auth()->check() || $userRole === 'admin';
@endphp

{{-- Navbar Superior --}}
<nav class="navbar navbar-expand-lg border-bottom border-secondary-subtle bg-dark px-3 py-2">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-warning fw-semibold">LTR701 · SCOM / LCON-I</span>
            <span class="navbar-brand mb-0 h6 fw-bold">Controle de Temperatura</span>
        </div>

        <ul class="nav nav-pills mx-auto my-2 my-lg-0 bg-body-tertiary rounded-3 p-1">
            <li class="nav-item">
                <button type="button" class="nav-link py-1 px-3 small fw-semibold d-flex align-items-center gap-2"
                        :class="activeTab === 'real' && 'active bg-success text-white'"
                        @click="switchTab('real')">
                    <span class="status-dot" :class="isBoardOnline() ? 'bg-light' : 'bg-warning'"></span>
                    Planta Real (NI-USB-6003)
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link py-1 px-3 small fw-semibold d-flex align-items-center gap-2"
                        :class="activeTab === 'sim' && 'active'"
                        :style="activeTab === 'sim' ? 'background-color:#7c3aed;color:#fff;' : ''"
                        @click="switchTab('sim')">
                    <span class="status-dot" :class="simRunning ? 'bg-info' : 'bg-secondary'"></span>
                    Sombra Digital (Simulação)
                </button>
            </li>
        </ul>

        <div class="d-flex align-items-center gap-3">
            <div class="text-end d-none d-md-block">
                <div class="small fw-semibold lh-1">{{ $userName }}</div>
                <span class="badge text-bg-secondary" style="font-size: 0.65rem;">VAZÃO FIXA NA MÁQUINA</span>
            </div>
            @auth
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-outline-light btn-sm">Sair</button>
                </form>
            @endauth
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
    {{-- ABA 1: PLANTA REAL (MATLAB + NI-USB-6003 · SOMENTE TEMPERATURA)       --}}
    {{-- ===================================================================== --}}
    <div x-show="activeTab === 'real'">

        <div class="rounded-3 p-3 mb-3 mode-banner-real">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-success fw-bold">MODO HARDWARE REAL</span>
                        <span class="small text-light fw-semibold" x-text="boardStatusText()"></span>
                    </div>
                    <div class="small text-secondary mt-1">
                        Controle PI de temperatura rodando a 100 Hz no MATLAB (NI-USB-6003 · AI0/AO0). Vazão mantida fixa no potenciômetro do kit.
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="input-group input-group-sm" style="min-width: 280px;">
                        <span class="input-group-text bg-dark border-success text-success fw-semibold">Controlador</span>
                        <select class="form-select bg-dark border-success text-light"
                                x-model="selectedDevice"
                                @change="fetchRealHistory(false)">
                            <option value="ALL">MATLAB-NI6003 (Automático)</option>
                            <template x-for="dev in devices" :key="dev.device_id">
                                <option :value="dev.device_id"
                                        x-text="(dev.online ? '● ' : '○ ') + dev.device_id + ' · ' + dev.ip + (dev.online ? ' (ONLINE)' : ' (offline há ' + dev.age_sec + 's)')">
                                </option>
                            </template>
                        </select>
                    </div>

                    <button type="button" class="btn btn-outline-light btn-sm" @click="fetchRealHistory(false)">
                        Atualizar
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="exportCsv(realReadings, 'temperatura_real_ltr701.csv')"
                            :disabled="realReadings.length === 0">CSV Real</button>
                </div>
            </div>
        </div>

        {{-- 4 KPIs Reais (Atualização em 1 Hz) --}}
        <div class="row g-2 mb-3">
            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Temperatura Atual (SENSOR 2)</div>
                        <div class="kpi-value text-warning">
                            <span class="num-fixed" x-text="fmt(realDisplay.temp, 3)"></span>
                            <span class="fs-6 text-secondary">°C</span>
                        </div>
                        <div class="small text-secondary">
                            SP: <span class="num-fixed-sm text-info" x-text="fmt(realState.setpoint_temp, 2)"></span> °C
                            · <span class="num-fixed-sm text-light" x-text="fmt(realDisplay.tempV, 2)"></span> V
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Erro de Regime e(t) = SP − T</div>
                        <div class="kpi-value" :class="Math.abs(realDisplay.error ?? 0) <= 0.5 ? 'text-success' : 'text-danger'">
                            <span class="num-fixed" x-text="fmt(realDisplay.error, 3)"></span>
                            <span class="fs-6 text-secondary">°C</span>
                        </div>
                        <div class="small text-secondary">
                            Status: <strong :class="realState.enable_temp ? 'text-success' : 'text-danger'"
                                            x-text="realState.enable_temp ? 'MALHA FECHADA ATIVA' : 'AQUECEDOR DESLIGADO'"></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Esforço Aquecedor (AO0 · ACT 2)</div>
                        <div class="kpi-value text-info">
                            <span class="num-fixed" x-text="fmt(realDisplay.uTemp, 3)"></span>
                            <span class="fs-6 text-secondary">V</span>
                        </div>
                        <div class="small text-secondary">
                            Potência: <span class="num-fixed-sm text-warning" x-text="fmt(((realDisplay.uTemp ?? 0) / 10) * 100, 1)"></span> % (Saturação 9,5 V)
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Dinâmica Kit 001 &amp; Rede</div>
                        <div class="small mt-1">
                            τ Térmica: <span class="num-fixed-sm text-warning fw-bold" x-text="fmt(realDisplay.tauTemp, 3)"></span> s
                        </div>
                        <div class="small mt-1">
                            RTT Sync: <span class="num-fixed-sm text-info fw-bold" x-text="fmt(realDisplay.rttMs, 1)"></span> ms
                        </div>
                        <div class="text-secondary" style="font-size: 0.72rem;">
                            Pend.: <strong x-text="realState.pending_commands"></strong> · <code x-text="realDisplay.device || '---'"></code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Painel de Controle PI de Temperatura (Real) --}}
        <div class="card panel-card rounded-3 mb-3 border-success-subtle">
            <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                <span class="fw-semibold text-warning small">Controlador PI Digital · Malha de Temperatura (ACTUATOR 2 / AO0 · SENSOR 2 / AI0)</span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn fw-semibold"
                            :class="realState.enable_temp ? 'btn-success' : 'btn-outline-success'"
                            @click="sendRealCommand('enable_temp', 1, true)">LIGAR AQUECEDOR</button>
                    <button type="button" class="btn fw-semibold"
                            :class="!realState.enable_temp ? 'btn-danger' : 'btn-outline-danger'"
                            @click="sendRealCommand('enable_temp', 0, true)">DESLIGAR</button>
                </div>
            </div>
            <div class="card-body py-3 px-3">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-lg-4">
                        <div class="d-flex justify-content-between small">
                            <span class="text-secondary">Setpoint Temperatura</span>
                            <span class="text-warning fw-bold"><span class="num-fixed-sm" x-text="fmt(realForm.spTemp, 1)"></span> °C</span>
                        </div>
                        <input type="range" class="form-range" min="20" max="85" step="0.5"
                               x-model.number="realForm.spTemp"
                               @input="queueRealSlider('setpoint_temp', realForm.spTemp)">
                    </div>

                    <div class="col-6 col-lg-4">
                        <div class="d-flex justify-content-between small">
                            <span class="text-secondary">Ganho Proporcional (Kp)</span>
                            <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(realForm.kpTemp, 3)"></span>
                        </div>
                        <input type="range" class="form-range" min="0" max="10.0" step="0.05"
                               x-model.number="realForm.kpTemp"
                               @input="queueRealSlider('kp_temp', realForm.kpTemp)">
                    </div>

                    <div class="col-6 col-lg-4">
                        <div class="d-flex justify-content-between small">
                            <span class="text-secondary">Ganho Integral (Ki)</span>
                            <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(realForm.kiTemp, 3)"></span>
                        </div>
                        <input type="range" class="form-range" min="0" max="3.0" step="0.01"
                               x-model.number="realForm.kiTemp"
                               @input="queueRealSlider('ki_temp', realForm.kiTemp)">
                    </div>
                </div>
            </div>
        </div>

        {{-- Gráficos da Planta Real --}}
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-8">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold small text-warning">Malha Real · Temperatura (°C), Setpoint e Sinal de Controle u(t) [V]</span>
                        <span class="small text-secondary" x-show="realReadings.length === 0">Aguardando MATLAB (POST /api/sync)</span>
                    </div>
                    <div class="card-body py-2"><div class="chart-box-lg"><canvas id="realTempChart"></canvas></div></div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold text-danger">
                        Erro em Tempo Real · e(t) = SP − T (°C)
                    </div>
                    <div class="card-body py-2"><div class="chart-box-lg"><canvas id="realErrorChart"></canvas></div></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================== --}}
    {{-- ABA 2: SOMBRA DIGITAL (SIMULAÇÃO ISOLADA DE TEMPERATURA · KIT 001)    --}}
    {{-- ===================================================================== --}}
    <div x-show="activeTab === 'sim'" x-cloak>

        <div class="rounded-3 p-3 mb-3 mode-banner-sim">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge fw-bold" style="background-color:#7c3aed;">MODO SOMBRA DIGITAL · ISOLADO</span>
                        <span class="small text-light fw-semibold"
                              x-text="simRunning ? 'Simulação Térmica Rodando (Kit 001 · G_T(s) = 0.8896 / (s + 0.1939))' : 'Simulação Pausada'"></span>
                    </div>
                    <div class="small text-secondary mt-1">
                        Simulação 100% local no navegador. Nenhum comando desta aba interfere no MATLAB ou na NI-USB-6003.
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm fw-semibold"
                            :class="simRunning ? 'btn-warning' : 'btn-info text-dark'"
                            @click="toggleSimEngine()"
                            x-text="simRunning ? 'Pausar Sombra' : 'Iniciar Sombra Digital'"></button>

                    <button type="button" class="btn btn-outline-danger btn-sm" @click="injectTempPerturbation(-4)">
                        Perturb. Térmica (−4 °C)
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" @click="resetSimEngine()">
                        Resetar (22 °C)
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="simDrawerOpen = !simDrawerOpen"
                            x-text="simDrawerOpen ? 'Ocultar Modelo & Ensaio' : '⚙ Modelo G_T(s) & Ensaio Degrau'"></button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="exportCsv(simReadings, 'sombra_temperatura_ltr701.csv')"
                            :disabled="simReadings.length === 0">CSV Sim.</button>
                </div>
            </div>

            {{-- Gaveta: Parâmetros da Planta + Ensaio em Degrau (Tustin 100 Hz) --}}
            <div class="mt-3 pt-3 border-top border-secondary-subtle" x-show="simDrawerOpen" x-cloak>
                <div class="row g-3">
                    <div class="col-12 col-lg-4">
                        <div class="card panel-card rounded-3 h-100">
                            <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold">
                                Modelo Térmico Kit 001 · G_T(s) = b₀ / (s + a₀)
                            </div>
                            <div class="card-body py-2 small">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="text-secondary">Numerador b₀</label>
                                        <input type="number" step="0.001" class="form-control form-control-sm" x-model.number="simModel.b0" @input="runStepTest()">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Polo a₀ (= 1/τ)</label>
                                        <input type="number" step="0.001" min="0.01" class="form-control form-control-sm" x-model.number="simModel.a0" @input="runStepTest()">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Temp. Ambiente (°C)</label>
                                        <input type="number" step="0.5" class="form-control form-control-sm" x-model.number="simModel.tAmb">
                                    </div>
                                    <div class="col-6">
                                        <label class="text-secondary">Ruído Sensor (0 a 3x)</label>
                                        <input type="number" step="0.1" min="0" max="3" class="form-control form-control-sm" x-model.number="simModel.noiseScale">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-8">
                        <div class="card panel-card rounded-3 h-100">
                            <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold">
                                Ensaio Determinístico em Degrau (Tustin 100 Hz · Usa Kp e Ki da Sombra)
                            </div>
                            <div class="card-body py-2 small">
                                <div class="row g-2 mb-2">
                                    <div class="col-2"><label class="text-secondary">t_f (s)</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.tFinal" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">T₀ (°C)</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.y0" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">SP 1 (°C)</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.sp1" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">SP 2 (°C)</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.sp2" @input="runStepTest()"></div>
                                    <div class="col-2"><label class="text-secondary">t_SP2 (s)</label><input type="number" class="form-control form-control-sm" x-model.number="stepTest.tSp2" @input="runStepTest()"></div>
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

        {{-- 4 KPIs Simulados --}}
        <div class="row g-2 mb-3">
            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Temperatura Simulada</div>
                        <div class="kpi-value text-warning">
                            <span class="num-fixed" x-text="fmt(simDisplay.temp, 3)"></span>
                            <span class="fs-6 text-secondary">°C</span>
                        </div>
                        <div class="small text-secondary">
                            SP: <span class="num-fixed-sm text-info" x-text="fmt(simState.setpoint_temp, 2)"></span> °C
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Erro Simulado e(t)</div>
                        <div class="kpi-value" :class="Math.abs(simDisplay.error ?? 0) <= 0.5 ? 'text-success' : 'text-danger'">
                            <span class="num-fixed" x-text="fmt(simDisplay.error, 3)"></span>
                            <span class="fs-6 text-secondary">°C</span>
                        </div>
                        <div class="small text-secondary">
                            Status: <strong :class="simState.enable_temp ? 'text-success' : 'text-danger'"
                                            x-text="simState.enable_temp ? 'ATIVO' : 'DESLIGADO'"></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Esforço Simulado u(t)</div>
                        <div class="kpi-value text-info">
                            <span class="num-fixed" x-text="fmt(simDisplay.uTemp, 3)"></span>
                            <span class="fs-6 text-secondary">V</span>
                        </div>
                        <div class="small text-secondary">
                            Potência: <span class="num-fixed-sm text-warning" x-text="fmt(((simDisplay.uTemp ?? 0) / 10) * 100, 1)"></span> %
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-secondary small">Constante Térmica (1/a₀)</div>
                        <div class="kpi-value text-light">
                            <span class="num-fixed" x-text="fmt(1 / Math.max(0.01, simModel.a0), 3)"></span>
                            <span class="fs-6 text-secondary">s</span>
                        </div>
                        <div class="small text-secondary">Fonte: <code>SOMBRA-LOCAL</code></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Controles Simulados --}}
        <div class="card panel-card rounded-3 mb-3" style="border-color: rgba(167,139,250,0.35);">
            <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
                <span class="fw-semibold text-warning small">Controlador PI Simulado · Temperatura (Isolado)</span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn fw-semibold"
                            :class="simState.enable_temp ? 'btn-success' : 'btn-outline-success'"
                            @click="simState.enable_temp = true">LIGAR</button>
                    <button type="button" class="btn fw-semibold"
                            :class="!simState.enable_temp ? 'btn-danger' : 'btn-outline-danger'"
                            @click="simState.enable_temp = false; simPhys.iTemp = 0">DESLIGAR</button>
                </div>
            </div>
            <div class="card-body py-3 px-3">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-lg-4">
                        <div class="d-flex justify-content-between small">
                            <span class="text-secondary">Setpoint Temperatura (simulado)</span>
                            <span class="text-warning fw-bold"><span class="num-fixed-sm" x-text="fmt(simState.setpoint_temp, 1)"></span> °C</span>
                        </div>
                        <input type="range" class="form-range" min="20" max="85" step="0.5" x-model.number="simState.setpoint_temp">
                    </div>

                    <div class="col-6 col-lg-4">
                        <div class="d-flex justify-content-between small">
                            <span class="text-secondary">Kp Temp.</span>
                            <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(simState.kp_temp, 3)"></span>
                        </div>
                        <input type="range" class="form-range" min="0" max="10.0" step="0.05" x-model.number="simState.kp_temp" @input="runStepTest()">
                    </div>

                    <div class="col-6 col-lg-4">
                        <div class="d-flex justify-content-between small">
                            <span class="text-secondary">Ki Temp.</span>
                            <span class="text-info fw-semibold num-fixed-sm" x-text="fmt(simState.ki_temp, 3)"></span>
                        </div>
                        <input type="range" class="form-range" min="0" max="3.0" step="0.01" x-model.number="simState.ki_temp" @input="runStepTest()">
                    </div>
                </div>
            </div>
        </div>

        {{-- Gráficos da Sombra Digital --}}
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-8">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold" style="color:#c4b5fd;">
                        Sombra Digital · Temperatura (°C), Setpoint e Controle u(t) [V]
                    </div>
                    <div class="card-body py-2"><div class="chart-box-lg"><canvas id="simTempChart"></canvas></div></div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card panel-card rounded-3 h-100">
                    <div class="card-header bg-transparent border-secondary-subtle py-2 small fw-semibold text-danger">
                        Erro Simulado · e(t) = SP − T (°C)
                    </div>
                    <div class="card-body py-2"><div class="chart-box-lg"><canvas id="simErrorChart"></canvas></div></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================== --}}
    {{-- DIAGRAMA DE BLOCOS SVG INTERATIVO (MALHA DE TEMPERATURA)              --}}
    {{-- ===================================================================== --}}
    <div class="card panel-card rounded-3 mb-3">
        <div class="card-header bg-transparent border-secondary-subtle d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold small">Diagrama de Blocos · Malha Fechada de Temperatura (Clique nos blocos)</span>
                <span class="badge" :class="activeTab === 'real' ? 'text-bg-success' : 'text-bg-primary'"
                      x-text="activeTab === 'real' ? 'Valores: Planta Real' : 'Valores: Sombra Digital'"></span>
            </div>
            <span class="small text-secondary">Vazão de ar mantida fixa manualmente no Kit LTR701</span>
        </div>
        <div class="card-body py-3">
            <div class="w-100 overflow-auto">
                <svg viewBox="0 0 1040 255" class="w-100" style="min-width: 820px; max-height: 260px;">
                    <defs>
                        <marker id="arrowBlue" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 1 L 10 5 L 0 9 z" fill="#38bdf8"/>
                        </marker>
                        <marker id="arrowWarn" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 1 L 10 5 L 0 9 z" fill="#f59e0b"/>
                        </marker>
                    </defs>

                    <rect x="12" y="12" width="435" height="232" rx="10" fill="rgba(56,189,248,0.04)" stroke="#38bdf8" stroke-dasharray="6,4" stroke-opacity="0.45"/>
                    <text x="24" y="30" fill="#7dd3fc" font-size="11" font-weight="600"
                          x-text="activeTab === 'real' ? 'DOMÍNIO DIGITAL · MATLAB (100 Hz) + SITE LARAVEL' : 'DOMÍNIO DIGITAL · SOMBRA DIGITAL (100 Hz)'"></text>

                    <rect x="655" y="12" width="372" height="232" rx="10" fill="rgba(251,146,60,0.04)" stroke="#fb923c" stroke-dasharray="6,4" stroke-opacity="0.45"/>
                    <text x="668" y="30" fill="#fdba74" font-size="11" font-weight="600">PLANTA FÍSICA · KIT ELWE LTR701 (KIT 001)</text>

                    {{-- Setpoint --}}
                    <g class="svg-block" @click="selectedBlock = 'sp'">
                        <rect x="22" y="68" width="96" height="52" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'sp' ? '#facc15' : '#475569'" stroke-width="2"/>
                        <text x="70" y="88" text-anchor="middle" fill="#94a3b8" font-size="10">SETPOINT (SP)</text>
                        <text x="70" y="108" text-anchor="middle" fill="#facc15" font-size="13" font-weight="700" font-family="monospace"
                              x-text="fmt(diag().sp, 1) + ' °C'"></text>
                    </g>

                    <line x1="118" y1="94" x2="152" y2="94" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>

                    {{-- Somador --}}
                    <circle cx="172" cy="94" r="18" fill="#0f172a" stroke="#38bdf8" stroke-width="2"/>
                    <text x="163" y="92" fill="#e2e8f0" font-size="12" font-weight="700">+</text>
                    <text x="175" y="108" fill="#f87171" font-size="13" font-weight="700">−</text>

                    <line x1="190" y1="94" x2="246" y2="94" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>
                    <text x="218" y="84" text-anchor="middle" fill="#f87171" font-size="10" font-family="monospace"
                          x-text="'e=' + fmt(diag().err, 2)"></text>

                    {{-- Controlador PI --}}
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

                    <line x1="432" y1="94" x2="478" y2="94" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>
                    <text x="455" y="84" text-anchor="middle" fill="#f59e0b" font-size="10" font-family="monospace"
                          x-text="fmt(diag().u, 2) + 'V'"></text>

                    {{-- Interface NI-USB-6003 --}}
                    <g class="svg-block" @click="selectedBlock = 'daq'">
                        <rect x="482" y="56" width="156" height="148" rx="8" fill="#172554"
                              :stroke="selectedBlock === 'daq' ? '#38bdf8' : '#3b82f6'" stroke-width="2"/>
                        <text x="560" y="78" text-anchor="middle" fill="#bfdbfe" font-size="11" font-weight="700">NI-USB-6003 (Dev3)</text>
                        <text x="560" y="98" text-anchor="middle" fill="#93c5fd" font-size="10">Saída Analógica AO0</text>
                        <text x="560" y="113" text-anchor="middle" fill="#e2e8f0" font-size="10" font-family="monospace">0–9.5 V → ACT 2</text>
                        <line x1="498" y1="130" x2="622" y2="130" stroke="#3b82f6" stroke-dasharray="3,3"/>
                        <text x="560" y="150" text-anchor="middle" fill="#93c5fd" font-size="10">Entrada SingleEnded AI0</text>
                        <text x="560" y="165" text-anchor="middle" fill="#e2e8f0" font-size="10" font-family="monospace">SENSOR 2 → 0–10 V</text>
                        <text x="560" y="188" text-anchor="middle" fill="#94a3b8" font-size="9">Acoplamento direto 0–10 V</text>
                    </g>

                    <line x1="638" y1="94" x2="682" y2="94" stroke="#f59e0b" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <text x="660" y="84" text-anchor="middle" fill="#fdba74" font-size="10">0–9.5 V</text>

                    {{-- Perturbação (Vazão fixa/válvula) --}}
                    <line x1="782" y1="36" x2="782" y2="54" stroke="#ef4444" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <text x="855" y="45" fill="#fca5a5" font-size="10">Vazão fixa / Válvula manual</text>

                    {{-- Planta Física --}}
                    <g class="svg-block" @click="selectedBlock = 'plant'">
                        <rect x="686" y="56" width="192" height="76" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'plant' ? '#fb923c' : '#ea580c'" stroke-width="2.2"/>
                        <text x="782" y="75" text-anchor="middle" fill="#fdba74" font-size="11" font-weight="700">ACTUATOR 2 + DUTO</text>
                        <text x="782" y="94" text-anchor="middle" fill="#e2e8f0" font-size="10" font-family="monospace">G_T(s) = 0.8896 / (s + 0.1939)</text>
                        <text x="782" y="114" text-anchor="middle" fill="#94a3b8" font-size="10" font-family="monospace"
                              x-text="'τ = ' + fmt(diag().tau, 3) + ' s'"></text>
                    </g>

                    {{-- Saída PV --}}
                    <line x1="878" y1="94" x2="914" y2="94" stroke="#f59e0b" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <g class="svg-block" @click="selectedBlock = 'plant'">
                        <rect x="918" y="68" width="98" height="52" rx="8" fill="#0f172a" stroke="#22c55e" stroke-width="2"/>
                        <text x="967" y="88" text-anchor="middle" fill="#86efac" font-size="10">SAÍDA (PV)</text>
                        <text x="967" y="108" text-anchor="middle" fill="#4ade80" font-size="13" font-weight="700" font-family="monospace"
                              x-text="fmt(diag().pv, 2) + ' °C'"></text>
                    </g>

                    {{-- Realimentação --}}
                    <polyline points="896,94 896,198 878,198" fill="none" stroke="#f59e0b" stroke-width="2"/>

                    <g class="svg-block" @click="selectedBlock = 'sensor'">
                        <rect x="686" y="168" width="192" height="58" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'sensor' ? '#a78bfa' : '#64748b'" stroke-width="2"/>
                        <text x="782" y="189" text-anchor="middle" fill="#e2e8f0" font-size="11" font-weight="700">SENSOR 2 (Termopar K)</text>
                        <text x="782" y="209" text-anchor="middle" fill="#94a3b8" font-size="10">Saída analógica 0...10 V</text>
                    </g>

                    <line x1="686" y1="198" x2="640" y2="198" stroke="#f59e0b" stroke-width="2" marker-end="url(#arrowWarn)"/>
                    <line x1="482" y1="198" x2="434" y2="198" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>

                    <g class="svg-block" @click="selectedBlock = 'sensor'">
                        <rect x="206" y="168" width="226" height="58" rx="8" fill="#1e293b"
                              :stroke="selectedBlock === 'sensor' ? '#a78bfa' : '#38bdf8'" stroke-width="2"/>
                        <text x="319" y="187" text-anchor="middle" fill="#c4b5fd" font-size="10.5" font-weight="700">
                            CALIBRAÇÃO + FILTRO 12,5 Hz
                        </text>
                        <text x="319" y="208" text-anchor="middle" fill="#38bdf8" font-size="10.5" font-family="monospace">
                            T(V) = 10.4 · V + 0.0795
                        </text>
                    </g>

                    <polyline points="206,198 172,198 172,115" fill="none" stroke="#38bdf8" stroke-width="2" marker-end="url(#arrowBlue)"/>
                </svg>
            </div>

            <div class="mt-2 p-2 px-3 rounded-2 bg-dark border border-secondary-subtle small d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong class="text-info" x-text="blockInfo().title + ':'"></strong>
                    <span class="text-light ms-1" x-text="blockInfo().desc"></span>
                </div>
                <span class="badge text-bg-secondary" x-text="blockInfo().ref"></span>
            </div>
        </div>
    </div>

    {{-- Logs de Comandos --}}
    @if ($isAdmin)
    <div class="card panel-card rounded-3">
        <div class="card-header bg-transparent border-secondary-subtle d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold text-danger-emphasis small">Logs de Comandos Enviados ao MATLAB (Apenas Aba Real)</span>
            <button type="button" class="btn btn-outline-danger btn-sm" @click="fetchLogs()">Atualizar</button>
        </div>
        <div class="table-responsive" style="max-height: 200px;">
            <table class="table table-dark table-striped table-sm align-middle mb-0 small">
                <thead><tr><th>Data/Hora</th><th>Usuário</th><th>IP</th><th>Ação</th></tr></thead>
                <tbody>
                    <template x-if="logs.length === 0">
                        <tr><td colspan="4" class="text-center text-secondary py-2">Nenhum comando registrado ainda.</td></tr>
                    </template>
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
function ltr701TempApp() {
    let realTempChart = null, realErrorChart = null;
    let simTempChart = null, simErrorChart = null;
    let stepRespChart = null, stepCtrlChart = null;

    const isAdmin = @json($isAdmin);
    const csrf    = document.querySelector('meta[name="csrf-token"]').content;
    const debounceTimers = {};

    return {
        activeTab: 'real',
        selectedBlock: 'pi',
        feedback: { text: '', type: 'success' },
        logs: [],

        // ---------- PLANTA REAL ----------
        selectedDevice: 'ALL',
        devices: [],
        realReadings: [],
        latestReal: null,
        realDisplay: { temp: null, tempV: null, uTemp: null, error: null, tauTemp: 5.157, rttMs: null, device: '' },
        realState: { enable_temp: false, setpoint_temp: 40.0, kp_temp: 0.800, ki_temp: 0.300, pending_commands: 0 },
        realForm: { spTemp: 40.0, kpTemp: 0.800, kiTemp: 0.300 },

        // ---------- SOMBRA DIGITAL ----------
        simRunning: false,
        simDrawerOpen: false,
        simReadings: [],
        latestSim: null,
        simTimer: null,
        simDisplay: { temp: 22.0, uTemp: 0.0, error: 18.0 },
        simState: { enable_temp: true, setpoint_temp: 40.0, kp_temp: 0.800, ki_temp: 0.300 },
        simPhys: { temp: 22.0, iTemp: 0.0, uTemp: 0.0 },
        simModel: { b0: 0.8896, a0: 0.1939, tAmb: 22.0, noiseScale: 1.0 },
        stepTest: { tFinal: 30, y0: 22, sp1: 40, sp2: 35, tSp2: 20, pert: 3, tPert: 12 },

        fmt(val, dec = 3) {
            if (val === null || val === undefined || isNaN(Number(val))) return '---';
            return Number(val).toFixed(dec);
        },

        async init() {
            this.initCharts();
            await this.fetchRealHistory(true);
            this.syncDisplays();
            if (isAdmin) await this.fetchLogs();
            this.runStepTest();

            // Displays numéricos fixos em 1 Hz
            setInterval(() => this.syncDisplays(), 1000);

            // Polling da Planta Real em 1 Hz para manter o servidor Windows leve
            setInterval(() => {
                if (this.activeTab === 'real') {
                    this.fetchRealHistory(false);
                }
            }, 1000);
        },

        syncDisplays() {
            if (this.latestReal) {
                const r = this.latestReal;
                this.realDisplay = {
                    temp: r.temp,
                    tempV: r.temp_v,
                    uTemp: r.u_temp,
                    error: r.error ?? (this.realState.setpoint_temp - r.temp),
                    tauTemp: r.tau_temp ?? 5.157,
                    rttMs: r.rtt_ms ?? 0,
                    device: r.device_id
                };
            }
            if (this.latestSim) {
                const s = this.latestSim;
                this.simDisplay = {
                    temp: s.temp,
                    uTemp: s.u_temp,
                    error: s.error
                };
            }
        },

        switchTab(tab) {
            this.activeTab = tab;
            this.$nextTick(() => {
                if (tab === 'real') {
                    realTempChart?.resize(); realErrorChart?.resize();
                } else {
                    simTempChart?.resize(); simErrorChart?.resize();
                    stepRespChart?.resize(); stepCtrlChart?.resize();
                }
            });
        },

        isBoardOnline() {
            return this.devices.some(d => d.online);
        },

        boardStatusText() {
            const online = this.devices.find(d => d.online);
            if (online) return `Conectado · ${online.device_id} (${online.ip} · último pacote ${online.last_iso})`;
            return 'Aguardando conexão do MATLAB (execute temperature_client_node.m)';
        },

        // ---------- COMANDOS E LEITURA REAL ----------
        queueRealSlider(name, value) {
            if (name === 'setpoint_temp') this.realState.setpoint_temp = Number(value);
            if (name === 'kp_temp') this.realState.kp_temp = Number(value);
            if (name === 'ki_temp') this.realState.ki_temp = Number(value);

            clearTimeout(debounceTimers[name]);
            debounceTimers[name] = setTimeout(() => {
                this.sendRealCommand(name, Number(value), false);
            }, 280);
        },

        async sendRealCommand(name, value, showToast = false) {
            try {
                const res = await fetch('/panel/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ name, value })
                });
                const data = await res.json();
                if (!res.ok) {
                    this.notify(data.message || 'Erro ao enviar comando.', 'error');
                    return;
                }
                this.realState = data.state;
                if (showToast) this.notify(`Comando "${name}" enviado ao MATLAB.`);
                if (isAdmin && showToast) this.fetchLogs();
            } catch (err) {
                this.notify('Erro de comunicação com o Laravel: ' + err.message, 'error');
            }
        },

        async fetchRealHistory(syncForms = false) {
            try {
                const devParam = encodeURIComponent(this.selectedDevice);
                const res = await fetch(`/panel/history?limit=80&device_id=${devParam}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;
                const data = await res.json();
                this.devices      = data.devices || [];
                this.realReadings = data.readings || [];

                if (data.state) {
                    this.realState = data.state;
                    if (syncForms) {
                        this.realForm = {
                            spTemp: data.state.setpoint_temp,
                            kpTemp: data.state.kp_temp,
                            kiTemp: data.state.ki_temp
                        };
                    }
                }
                if (this.realReadings.length > 0) {
                    this.latestReal = this.realReadings[this.realReadings.length - 1];
                }
                this.updateChartPair(realTempChart, realErrorChart, this.realReadings, this.realState.setpoint_temp);
            } catch (_) {}
        },

        // ---------- SOMBRA DIGITAL (TEMPERATURA · KIT 001) ----------
        toggleSimEngine() {
            if (this.simRunning) {
                clearInterval(this.simTimer);
                this.simTimer = null;
                this.simRunning = false;
            } else {
                this.simRunning = true;
                this.stepSimEngine();
                this.simTimer = setInterval(() => this.stepSimEngine(), 500);
            }
        },

        resetSimEngine() {
            this.simPhys = { temp: this.simModel.tAmb, iTemp: 0.0, uTemp: 0.0 };
            this.simReadings = [];
            this.stepSimEngine();
            this.syncDisplays();
        },

        injectTempPerturbation(delta) {
            this.simPhys.temp = Math.max(15, this.simPhys.temp + delta);
            this.notify(`Perturbação térmica (${delta} °C) aplicada na Sombra Digital.`);
        },

        stepSimEngine() {
            const subSteps = 50;
            const dt = 0.01; // 50 * 10 ms = 0.5 s
            const m  = this.simModel;
            const st = this.simState;
            let { temp: t, iTemp, uTemp } = this.simPhys;

            for (let s = 0; s < subSteps; s++) {
                if (st.enable_temp) {
                    const errT  = st.setpoint_temp - t;
                    const iCand = iTemp + st.ki_temp * errT * dt;
                    const uRaw  = st.kp_temp * errT + iCand;
                    uTemp = Math.max(0, Math.min(9.5, uRaw));
                    if (uRaw === uTemp || (uTemp >= 9.5 && errT < 0) || (uTemp <= 0 && errT > 0)) {
                        iTemp = iCand;
                    }
                } else {
                    iTemp = 0; uTemp = 0;
                }

                // Modelo 1a ordem Kit 001: d(T - Tamb)/dt = b0*u - a0*(T - Tamb)
                const dTdt = (m.b0 * uTemp) - (m.a0 * (t - m.tAmb));
                t = Math.max(15, Math.min(115, t + dTdt * dt));
            }

            this.simPhys = { temp: t, iTemp, uTemp };

            const ns = Math.max(0, Number(m.noiseScale) || 0);
            const sigma = ((uTemp > 0.1 || t > m.tAmb + 1.5) ? (0.04 + 0.012 * uTemp) : 0.025) * ns;
            const measTemp = Number((t + this.gauss(sigma)).toFixed(3));
            const errNow   = Number((st.setpoint_temp - measTemp).toFixed(3));

            const reading = {
                id: Date.now(),
                device_id: 'SOMBRA-LOCAL',
                temp: measTemp,
                u_temp: Number(uTemp.toFixed(3)),
                sp_temp: st.setpoint_temp,
                error: errNow,
                ts: new Date().toLocaleTimeString('pt-BR', { hour12: false }),
                ts_full: new Date().toLocaleString('pt-BR')
            };

            this.simReadings.push(reading);
            if (this.simReadings.length > 80) this.simReadings.shift();
            this.latestSim = reading;
            this.updateChartPair(simTempChart, simErrorChart, this.simReadings, st.setpoint_temp);
        },

        gauss(sigma) {
            if (sigma <= 0) return 0;
            const u1 = Math.max(1e-7, Math.random());
            const u2 = Math.random();
            const z  = Math.sqrt(-2 * Math.log(u1)) * Math.cos(2 * Math.PI * u2);
            return Math.max(-2.5 * sigma, Math.min(2.5 * sigma, z * sigma));
        },

        runStepTest() {
            if (!stepRespChart || !stepCtrlChart) return;
            const st = this.stepTest;
            const Kp = this.simState.kp_temp;
            const Ki = this.simState.ki_temp;
            const b0 = this.simModel.b0;
            const a0 = this.simModel.a0;
            const tAmb = this.simModel.tAmb;

            const dt = 0.01, c = 2 / dt;
            const N  = Math.floor(Math.max(5, Math.min(120, st.tFinal)) / dt);
            const d0 = c + a0, d1 = a0 - c;

            let u0 = 0, u1 = 0, dy0 = st.y0 - tAmb, dy1 = st.y0 - tAmb, I = 0;
            const labels = [], yOut = [], spOut = [], eOut = [], uOut = [];
            const pertStep = Math.round(st.tPert / dt);
            const sampleEvery = Math.max(5, Math.floor(N / 200));

            for (let i = 0; i <= N; i++) {
                const t  = i * dt;
                const sp = t >= st.tSp2 ? st.sp2 : st.sp1;
                const yCurr = tAmb + dy0;
                const e  = sp - yCurr;
                const iCand = I + Ki * e * dt;
                const uRaw  = Kp * e + iCand;
                const ctrl  = Math.max(0, Math.min(9.5, uRaw));
                if (uRaw === ctrl || (ctrl >= 9.5 && e < 0) || (ctrl <= 0 && e > 0)) I = iCand;

                u1 = u0; u0 = ctrl;
                dy1 = dy0;
                dy0 = (b0 * (u0 + u1) - d1 * dy1) / d0;
                if (i === pertStep && pertStep > 0) dy0 -= st.pert;

                if (i % sampleEvery === 0) {
                    labels.push(t.toFixed(1) + 's');
                    yOut.push(Number((tAmb + dy0).toFixed(2)));
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

        // ---------- DIAGRAMA DE BLOCOS SVG ----------
        diag() {
            const st  = this.activeTab === 'real' ? this.realState : this.simState;
            const dsp = this.activeTab === 'real' ? this.realDisplay : this.simDisplay;
            const pv  = dsp.temp ?? 22;
            return {
                sp: st.setpoint_temp,
                pv,
                err: st.setpoint_temp - pv,
                kp: st.kp_temp,
                ki: st.ki_temp,
                enabled: st.enable_temp,
                u: dsp.uTemp ?? 0,
                tau: this.activeTab === 'real' ? (dsp.tauTemp ?? 5.157) : (1 / Math.max(0.01, this.simModel.a0))
            };
        },

        blockInfo() {
            const d = this.diag();
            const map = {
                sp: {
                    title: 'Referência / Setpoint (SP)',
                    desc: `Alvo atual: ${this.fmt(d.sp, 1)} °C. Na aba Real, mover o slider envia o novo valor ao Laravel e o MATLAB sincroniza em até 0,5 s.`,
                    ref: 'LCON-I · Malha Fechada'
                },
                pi: {
                    title: 'Controlador PI Digital (100 Hz no MATLAB)',
                    desc: `Kp = ${this.fmt(d.kp, 3)}, Ki = ${this.fmt(d.ki, 3)}, erro = ${this.fmt(d.err, 2)} °C. Com anti-windup (0–9,5 V) e reset do integrador ao desligar.`,
                    ref: 'Ativ. 08 e 09'
                },
                daq: {
                    title: 'Placa NI-USB-6003 (Dev3)',
                    desc: 'Conexão direta 0–10 V com o Kit LTR701: AI0 lê o SENSOR 2 (SingleEnded) e AO0 comanda o ACTUATOR 2 (aquecedor).',
                    ref: 'Hardware DAQ'
                },
                plant: {
                    title: 'Planta Térmica · Kit ELWE LTR701 (Kit 001)',
                    desc: 'Modelo identificado (fit 87,48%): G_T(s) = 0,8896 / (s + 0,1939), τ ≈ 5,157 s. Vazão de ar fixada manualmente no soprador.',
                    ref: 'Ativ. 07 · Parte C'
                },
                sensor: {
                    title: 'SENSOR 2 (Termopar K) + Filtro Passa-Baixas',
                    desc: 'Tensão 0–10 V convertida por T(V) = 10,4·V + 0,0795 e filtrada digitalmente em fc = 12,5 Hz (Tustin) antes do cálculo do erro.',
                    ref: 'Ativ. 07 · Parte A'
                }
            };
            return map[this.selectedBlock] || map.pi;
        },

        // ---------- CHART.JS ----------
        initCharts() {
            const dualOpts = {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: 10 }, grid: { color: 'rgba(148,163,184,0.08)' } },
                    y: { position: 'left', title: { display: true, text: 'Temperatura (°C)', color: '#fb923c' }, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } },
                    y1: { position: 'right', min: 0, max: 10.5, title: { display: true, text: 'Controle u [V]', color: '#38bdf8' }, ticks: { color: '#94a3b8' }, grid: { drawOnChartArea: false } }
                },
                plugins: { legend: { labels: { color: '#e2e8f0', boxWidth: 14 } } }
            };

            const errOpts = {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: 8 }, grid: { color: 'rgba(148,163,184,0.08)' } },
                    y: { title: { display: true, text: 'Erro (°C)', color: '#f87171' }, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } }
                },
                plugins: { legend: { labels: { color: '#e2e8f0', boxWidth: 12 } } }
            };

            const makePair = (tId, eId) => ([
                new Chart(document.getElementById(tId), {
                    type: 'line',
                    data: { labels: [], datasets: [
                        { label: 'Temperatura (°C)', data: [], yAxisID: 'y', borderColor: '#fb923c', backgroundColor: 'rgba(251,146,60,0.12)', fill: true, borderWidth: 2.2, tension: 0.2, pointRadius: 0 },
                        { label: 'Setpoint (°C)', data: [], yAxisID: 'y', borderColor: '#facc15', borderDash: [6,4], borderWidth: 1.8, fill: false, pointRadius: 0 },
                        { label: 'Controle u (V)', data: [], yAxisID: 'y1', borderColor: '#38bdf8', borderWidth: 1.6, fill: false, pointRadius: 0 }
                    ]},
                    options: dualOpts
                }),
                new Chart(document.getElementById(eId), {
                    type: 'line',
                    data: { labels: [], datasets: [
                        { label: 'Erro e(t) [°C]', data: [], borderColor: '#f87171', backgroundColor: 'rgba(248,113,113,0.12)', fill: true, borderWidth: 1.8, tension: 0.2, pointRadius: 0 }
                    ]},
                    options: errOpts
                })
            ]);

            [realTempChart, realErrorChart] = makePair('realTempChart', 'realErrorChart');
            [simTempChart, simErrorChart]   = makePair('simTempChart', 'simErrorChart');

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
                    { label: 'Temp (°C)', data: [], borderColor: '#22c55e', borderWidth: 2, pointRadius: 0 },
                    { label: 'SP (°C)', data: [], borderColor: '#38bdf8', borderDash: [5,4], borderWidth: 1.6, pointRadius: 0 },
                    { label: 'Erro', data: [], borderColor: '#f87171', borderWidth: 1.1, pointRadius: 0 }
                ]},
                options: simpleOpts('°C')
            });

            stepCtrlChart = new Chart(document.getElementById('stepCtrlChart'), {
                type: 'line',
                data: { labels: [], datasets: [
                    { label: 'u(t) [V]', data: [], borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.14)', fill: true, borderWidth: 1.8, pointRadius: 0 }
                ]},
                options: simpleOpts('Tensão [V]', 10.5)
            });
        },

        updateChartPair(tChart, eChart, list, fallbackSp) {
            const labels = list.map(r => r.ts);
            tChart.data.labels = labels;
            tChart.data.datasets[0].data = list.map(r => r.temp);
            tChart.data.datasets[1].data = list.map(r => r.sp_temp ?? fallbackSp);
            tChart.data.datasets[2].data = list.map(r => r.u_temp);
            tChart.update('none');

            eChart.data.labels = labels;
            eChart.data.datasets[0].data = list.map(r => r.error ?? ((r.sp_temp ?? fallbackSp) - r.temp));
            eChart.update('none');
        },

        async fetchLogs() {
            const res = await fetch('/panel/logs', { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            this.logs = data.logs || [];
        },

        exportCsv(list, filename) {
            const header = 'id,horario,dispositivo,temp_c,sp_temp_c,erro_c,u_temp_v,rtt_ms\n';
            const rows = list.map(r => [
                r.id, `"${r.ts_full || r.ts}"`, r.device_id,
                r.temp ?? '', r.sp_temp ?? '', r.error ?? '', r.u_temp ?? '', r.rtt_ms ?? ''
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