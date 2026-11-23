<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso · Soprador Térmico (SCOM / LCON-I)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <style>
        body {
            min-height: 100vh;
            background: radial-gradient(circle at top right, #1e293b 0%, #0f172a 60%, #020617 100%);
        }
        .auth-card {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(148, 163, 184, 0.2);
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">

<div class="container" style="max-width: 440px;" x-data="{ tab: '{{ old('name') ? 'register' : 'login' }}' }">
    <div class="text-center mb-4">
        <span class="badge rounded-pill text-bg-warning mb-2 px-3 py-2 fw-semibold">
            UNESP · Projeto Integrador
        </span>
        <h1 class="h3 fw-bold mb-1">Supervisório Soprador Térmico</h1>
        <p class="text-secondary small mb-0">Telemetria em tempo real, controle e auditoria criptografada</p>
    </div>

    <div class="card auth-card shadow-lg rounded-4">
        <div class="card-body p-4">
            <ul class="nav nav-pills nav-fill mb-4 bg-dark-subtle rounded-3 p-1">
                <li class="nav-item">
                    <button type="button" class="nav-link" :class="tab === 'login' && 'active'" @click="tab = 'login'">
                        Entrar
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link" :class="tab === 'register' && 'active'" @click="tab = 'register'">
                        Criar Conta
                    </button>
                </li>
            </ul>

            @if ($errors->any())
                <div class="alert alert-danger py-2 small">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Formulário de Login --}}
            <form method="POST" action="{{ route('login.post') }}" x-show="tab === 'login'">
                @csrf
                <div class="mb-3">
                    <label for="login_email" class="form-label small text-secondary">E-mail</label>
                    <input type="email" id="login_email" name="email" class="form-control"
                           value="{{ old('email') }}" placeholder="admin@unesp.br" required autofocus>
                </div>

                <div class="mb-3">
                    <label for="login_password" class="form-label small text-secondary">Senha</label>
                    <input type="password" id="login_password" name="password" class="form-control"
                           placeholder="••••••••" required>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label small text-secondary" for="remember">
                        Manter conectado
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                    Acessar Painel
                </button>
            </form>

            {{-- Formulário de Cadastro --}}
            <form method="POST" action="{{ route('register.post') }}" x-show="tab === 'register'" x-cloak>
                @csrf
                <div class="mb-3">
                    <label for="reg_name" class="form-label small text-secondary">Nome completo</label>
                    <input type="text" id="reg_name" name="name" class="form-control"
                           value="{{ old('name') }}" placeholder="Seu nome" required>
                </div>

                <div class="mb-3">
                    <label for="reg_email" class="form-label small text-secondary">E-mail</label>
                    <input type="email" id="reg_email" name="email" class="form-control"
                           value="{{ old('email') }}" placeholder="voce@unesp.br" required>
                </div>

                <div class="mb-3">
                    <label for="reg_password" class="form-label small text-secondary">Senha (mín. 6 caracteres)</label>
                    <input type="password" id="reg_password" name="password" class="form-control" required>
                </div>

                <div class="mb-4">
                    <label for="reg_password_confirmation" class="form-label small text-secondary">Confirmar senha</label>
                    <input type="password" id="reg_password_confirmation" name="password_confirmation" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-success w-100 fw-semibold py-2">
                    Criar Conta de Operador
                </button>
            </form>

            @if (config('app.debug'))
                <div class="mt-4 pt-3 border-top border-secondary-subtle small text-secondary">
                    <div class="fw-semibold text-light mb-1">Credenciais do Seed (ambiente local):</div>
                    <div><strong>Admin:</strong> <code>admin@unesp.br</code> / <code>12345678</code></div>
                    <div><strong>Operador:</strong> <code>operador@unesp.br</code> / <code>12345678</code></div>
                </div>
            @endif
        </div>
    </div>
</div>

</body>
</html>