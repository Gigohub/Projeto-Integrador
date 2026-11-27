# Projeto Integrador (SCOM + LCON-I) — Supervisório Térmico ELWE LTR701

**Instituição:** UNESP — Câmpus de Sorocaba (ICT)

**Disciplinas:** Sistemas Computacionais (SCOM) e Laboratório de Controle I (LCON-I)

**Integrantes:**

* Caio Gabriel Pereira de Morais — RA: `241270626`
* João Augusto Oliveira Candido da Silva — RA: `241270847`
* Lucas Yuta Ameda — RA: `241270618`
* Pedro Henrique de Melo Rosa — RA: `241270031`

## 1. O que o Sistema Faz e o que Ele Opera

Este repositório implementa uma plataforma completa de **monitoramento, controle digital em malha fechada, simulação externa e auditoria criptografada** para o soprador térmico didático **ELWE LTR701 (Kit 001)**.

Na arquitetura final validada em bancada (**sem ESP32**), o **MATLAB** atua como controlador de tempo real diretamente sobre a placa **NI-USB-6003**, enquanto o **site Laravel** atua como interface web segura e camada de persistência em banco de dados.

### 1.1. Fluxo de Operação (Arquitetura Validada)

```text
[Kit ELWE LTR701] <==(0..10 V)==> [NI-USB-6003 (Dev1)] <==> [MATLAB (20 Hz)]
                                                                 ^
                                                     TCP 127.0.0.1:2000 (20 Hz)
                                                                 v
                                                     [web/tcp_bridge.php]
                                                        /            \
                          Estado rapido (JSON)         /              \  Persistencia 2 Hz (AES-256-CBC)
                                                      v                v
                                       [Laravel (porta 8000)] <==> [MySQL: projeto_integrador]
                                                      ^
                                                 HTTP / Login
                                                      v
                                        [Navegador: Admin ou Operador]
```

### 1.2. O que Cada Módulo Opera

* **Controlador Real (`hardware/matlab/temperature_client_node.m`):**
  * Roda a malha fechada a $20\text{ Hz}$ ($T_s = 0{,}05\text{ s}$) com leitura do **SENSOR 2 (Termopar Tipo K)** convertida por $T(V) = 10{,}4 \cdot V + 0{,}0795$ ($^\circ\text{C}$) e filtro passa-baixas digital bilinear ($f_c = 4\text{ Hz}$).
  * Calcula o **Controlador PI Digital** com anti-windup e saturação segura em $0\text{ a }9{,}5\text{ V}$ no aquecedor (`AO 0` / **ACTUATOR 2**).
  * Gerencia o soprador (`AO 1` / **ACTUATOR 1**) e o estado da malha por **flags recebidas do site** (`flag_controlador` e `flag_soprador`), sem precisar fechar o MATLAB:
    * **Controlador LIGADO (`flag_controlador = 1`, `flag_soprador = 1`):** o soprador opera em sinal constante de $1{,}5\text{ V}$ (`uFanConst`) enquanto o controlador PI varia a tensão do aquecedor (`AO 0`) para rastrear o setpoint.
    * **Controlador DESLIGADO (`flag_controlador = 0`, `flag_soprador = 0`):** o integrador é zerado e o MATLAB envia $0{,}0\text{ V}$ no aquecedor (`AO 0`) e $0{,}0\text{ V}$ no soprador (`AO 1`) imediatamente, mantendo o script aberto e enviando as leituras de temperatura para o gráfico.
  * Garante encerramento seguro via `onCleanup` ao pressionar `Ctrl + C`, zerando `[0.0, 0.0]` na placa NI e fechando o socket TCP.

* **Simulador Externo (`hardware/matlab/temperature_simulator_node.m`):**
  * Substitui a placa NI-USB-6003 fora do laboratório mantendo 100% do protocolo TCP, das flags e da lógica de controle PI com soprador constante.
  * Emula a dinâmica térmica identificada do Kit 001:

    $$
    G_T(s) = \frac{0{,}8896}{s + 0{,}1939} \quad (\tau \approx 5{,}157\text{ s})
    $$

    com acoplamento convectivo do soprador, atraso de transporte ($\approx 0{,}8\text{ s}$) e ruído gaussiano no sensor.

* **Ponte TCP (`web/tcp_bridge.php`):**
  * Ouve na porta `127.0.0.1:2000`, responde instantaneamente ao MATLAB a $20\text{ Hz}$ com `flag_controlador`, `status`, `setpoint`, `kp` e `ki`, grava leituras criptografadas na tabela `readings` a $2\text{ Hz}$ e marca comandos como confirmados (`acked = 1`).

* **Supervisório Web e Segurança (`web/`):**
  * **Autenticação obrigatória** em `/login` com controle de acesso por perfil (**RBAC**): `admin` e `user`.
  * **Criptografia em repouso (AES-256-CBC)** via `APP_KEY` do Laravel nas tabelas `readings` (`value_enc`), `commands` (`value_enc`) e `logs` (`action_enc`).
  * **Auditoria restrita:** rota `GET /panel/logs` protegida por `EnsureAdmin` (retorna HTTP `403` para operadores comuns).
  * **Visualização e Exportação:** gráfico em tempo real com janela deslizante de 80 amostras (`GET /panel/history`), Sombra Digital isolada no navegador, diagrama SVG interativo e **CSV Completo direto do MySQL** (`GET /panel/export-csv`) contendo todas as variações de setpoint, tensões de aquecedor/soprador e flags do ensaio inteiro.

### 1.3. Estrutura Principal do Repositório

| Caminho | Função |
| :--- | :--- |
| `hardware/matlab/temperature_client_node.m` | Controlador PI real (NI-USB-6003 `Dev1` + Kit LTR701) via TCP porta `2000`. |
| `hardware/matlab/temperature_simulator_node.m` | Simulador térmico externo (sem placa NI) via TCP porta `2000`. |
| `web/tcp_bridge.php` | Ponte TCP em PHP que conecta o MATLAB ao Laravel e grava no MySQL. |
| `web/routes/web.php` | Rotas autenticadas (`/`, `/panel/history`, `/panel/send`, `/panel/export-csv`, `/panel/logs`). |
| `web/app/Http/Controllers/TelemetryController.php` | Leitura/escrita criptografada no MySQL, exportação CSV completa e cache rápido de estado. |
| `web/app/Http/Controllers/AuthController.php` | Login, cadastro de operadores, logout e auditoria de sessão. |
| `web/app/Http/Middleware/EnsureAdmin.php` | Bloqueio RBAC (HTTP `403`) das rotas administrativas para perfil `user`. |
| `web/app/Models/{Reading,Command,AuditLog,User}.php` | Models Eloquent com criptografia/descriptografia automática em AES-256-CBC. |
| `web/resources/views/dashboard.blade.php` | Painel em tempo real (Bootstrap 5 + Alpine.js + Chart.js + SVG interativo). |
| `web/resources/views/login.blade.php` | Tela de autenticação e cadastro de operadores. |

## 2. O que Precisa Fora do Código (Pré-requisitos)

### 2.1. Software Necessário no Computador do Laboratório

1. **XAMPP (Windows):** fornece **MySQL** (porta `3306`) e **PHP** $8.3+$ (geralmente em `C:\xampp\php\php.exe`).
2. **Composer:** necessário apenas se a pasta `web/vendor/` não tiver sido copiada junto com o projeto.
3. **MATLAB (R2021b ou superior):**
   * Para a **bancada real:** exige **Data Acquisition Toolbox** + driver **NI-DAQmx** instalado no Windows.
   * Para o **simulador externo:** exige apenas MATLAB com suporte a `tcpclient` (não precisa de driver NI).
4. **Navegador atualizado:** Google Chrome ou Microsoft Edge.

### 2.2. Hardware e Ligação Física (Somente Modo Planta Real)

* **Equipamentos:** Kit Didático **ELWE LTR701 (Kit 001)**, placa **NI-USB-6003** com cabo USB e **5 cabos** de conexão (sinal + terras).
* **Chaves do Painel do Kit LTR701:** coloque **ACTUATOR 1 (Soprador)** e **ACTUATOR 2 (Aquecedor)** na posição **`extern`**.
* **Válvula Manual de Entrada de Ar:** mantenha na **posição vertical (aberta)** — nunca bloqueie totalmente o fluxo com o aquecedor ligado.

| Pino na NI-USB-6003 | Borne no Kit ELWE LTR701 | Faixa / Modo | Função |
| :--- | :--- | :--- | :--- |
| **`AI 0`** | **SENSOR 2** (Termopar K) | $0\text{ a }10\text{ V}$ (*SingleEnded*) | Leitura da temperatura do duto |
| **`AI GND`** | **GND** dos sensores ($\perp$) | Referência $0\text{ V}$ | Terra obrigatório da entrada `AI 0` |
| **`AO 0`** | **ACTUATOR 2** (Aquecedor) | $0\text{ a }9{,}5\text{ V}$ (PI $20\text{ Hz}$) | Sinal de controle variável do aquecedor |
| **`AO 1`** | **ACTUATOR 1** (Soprador) | $0\text{ V}$ ou $1{,}5\text{ V}$ | Sinal constante ($1{,}5\text{ V}$) no ON e $0\text{ V}$ no OFF (via flags) |
| **`AO GND`** | **GND** dos atuadores ($\perp$) | Referência $0\text{ V}$ | Terra obrigatório de `AO 0` e `AO 1` (sem ele o kit não responde) |

## 3. Preparação em um Computador Novo (Primeira Execução)

Execute esta sequência apenas na **primeira vez** que instalar o projeto em um computador do laboratório.

### Passo 1 — Copiar ou clonar o repositório

```powershell
cd $HOME\Desktop
git clone <URL_DO_REPOSITORIO> Projeto-Integrador
cd Projeto-Integrador
```

*(Se trouxe por pen drive, apenas copie a pasta inteira para o Desktop e abra o PowerShell dentro dela.)*

### Passo 2 — Ligar o MySQL e criar o banco `projeto_integrador`

Abra o **XAMPP Control Panel** e clique em **Start** no **MySQL** (aguarde ficar verde na porta `3306`). Depois crie o banco via terminal:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS projeto_integrador CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Passo 3 — Criar ou conferir o arquivo `web/.env`

Como o `.env` não vai para o Git, crie `web/.env` com o conteúdo abaixo (padronizado em `http://127.0.0.1:8000` para evitar erro `419` de sessão/CSRF):

```env
APP_NAME="Soprador Termico - SCOM/LCON"
APP_ENV=local
APP_KEY=base64:XoMJDfJSOi9J/v0LkA2U5HgGZur5vT0Wj6vXOd2KV7w=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=pt_BR

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=projeto_integrador
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

DEVICE_API_TOKEN=token_do_dispositivo_123
BCRYPT_ROUNDS=12
```

### Passo 4 — Instalar dependências, criar tabelas e popular usuários (Comando Único)

No PowerShell, na raiz do projeto:

```powershell
cd web
$php = if (Get-Command php -ErrorAction SilentlyContinue) { "php" } else { "C:\xampp\php\php.exe" }
if (!(Test-Path "vendor")) { composer install }
Remove-Item storage\app\ltr701_*.json -ErrorAction SilentlyContinue
& $php artisan migrate:fresh --seed
& $php artisan optimize:clear
Write-Host "[OK] Ambiente pronto!" -ForegroundColor Green
```

### Passo 5 — Conferir o ID da NI-USB-6003 (apenas na bancada real)

No *Command Window* do MATLAB, execute:

```matlab
daqlist("ni")
```

Verifique o valor da coluna `DeviceID` (ex.: `Dev1`, `Dev2` ou `Dev3`). Se for diferente de `"Dev1"`, altere a linha `dev_name = "Dev1";` em `hardware/matlab/temperature_client_node.m`.

## 4. Como Atualizar o Projeto

Sempre que puxar novas alterações do GitHub em um computador que já teve o setup feito:

```powershell
# 1. Na raiz do projeto, baixe as atualizacoes
git pull

# 2. Atualize dependencias (se composer.json mudou), banco e caches
cd web
$php = if (Get-Command php -ErrorAction SilentlyContinue) { "php" } else { "C:\xampp\php\php.exe" }
if (Test-Path "composer.json") { composer install --no-interaction }
& $php artisan migrate --force
& $php artisan optimize:clear
Remove-Item storage\app\ltr701_*.json -ErrorAction SilentlyContinue
```

> **Atenção:** se o `php tcp_bridge.php` ou o `php artisan serve` estiverem abertos durante a atualização, encerre ambos (`Ctrl + C` ou `parar_ltr701.bat`) e inicie novamente para carregar o código novo em memória. Se quiser zerar todos os ensaios antigos do banco para começar uma bateria limpa, rode `& $php artisan migrate:fresh --seed`.

## 5. Uso e Inicialização Automática

### Opção A — Scripts Automáticos de 1 Clique (`iniciar_ltr701.bat` e `parar_ltr701.bat`)

Salve os dois arquivos abaixo na **raiz do projeto** (ao lado das pastas `web/` e `hardware/`). O `iniciar_ltr701.bat` detecta PHP e MATLAB automaticamente, verifica/inicia o MySQL do XAMPP, limpa caches, abre Laravel + Ponte TCP + Navegador e dispara o MATLAB em modo interativo (`-nosplash -nodesktop -r`) para manter o `Ctrl + C` seguro.

#### `iniciar_ltr701.bat`

```bat
@echo off
setlocal EnableDelayedExpansion
title LTR701 - Inicializador Automatico (SCOM / LCON-I)
color 0A

set "ROOT_DIR=%~dp0"
if "%ROOT_DIR:~-1%"=="\" set "ROOT_DIR=%ROOT_DIR:~0,-1%"
set "WEB_DIR=%ROOT_DIR%\web"
set "MATLAB_DIR=%ROOT_DIR%\hardware\matlab"

echo ========================================================================
echo   SUPERVISORIO ELWE LTR701 - INICIALIZACAO AUTOMATICA DE LABORATORIO
echo ========================================================================
echo.

:: 1. Localizar PHP (PATH ou XAMPP)
set "PHP_EXE="
where php >nul 2>nul && set "PHP_EXE=php"
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE (
    color 0C
    echo [ERRO] PHP nao encontrado no PATH nem em C:\xampp\php\php.exe!
    pause
    exit /b 1
)
echo [OK] PHP detectado: %PHP_EXE%

:: 2. Verificar se o MySQL (porta 3306) esta ativo; tenta iniciar pelo XAMPP se fechado
powershell -NoProfile -Command "if (Test-NetConnection -ComputerName 127.0.0.1 -Port 3306 -InformationLevel Quiet) { exit 0 } else { exit 1 }"
if errorlevel 1 (
    echo [AVISO] MySQL nao respondeu na porta 3306. Tentando iniciar MySQL do XAMPP...
    if exist "C:\xampp\mysql_start.bat" (
        start "LTR701 - MySQL XAMPP" /min cmd /c "C:\xampp\mysql_start.bat"
        timeout /t 4 /nobreak >nul
    ) else (
        echo [ERRO] Abra o XAMPP Control Panel e inicie o MySQL antes de continuar.
        pause
        exit /b 1
    )
)
echo [OK] MySQL ativo na porta 3306.

:: 3. Menu de modo de operacao
echo.
echo Selecione o modo de execucao do MATLAB:
echo   [1] Planta Real - NI-USB-6003 (temperature_client_node.m)
echo   [2] Simulador Externo - Sem bancada (temperature_simulator_node.m)
echo   [3] Somente Site Laravel + Ponte TCP (abrir MATLAB manualmente)
echo.
set /p MODO="Digite 1, 2 ou 3 e pressione ENTER: "

:: 4. Limpar estado JSON antigo e caches do Laravel
echo.
echo [PREP] Limpando cache e preparando servidor...
del /q "%WEB_DIR%\storage\app\ltr701_*.json" 2>nul
pushd "%WEB_DIR%"
"%PHP_EXE%" artisan optimize:clear >nul 2>nul
popd

:: 5. Subir Terminal 1 (Laravel) e Terminal 2 (Ponte TCP porta 2000)
echo [START] Iniciando Laravel em http://127.0.0.1:8000 ...
start "LTR701 - 1. Site Laravel (Porta 8000)" cmd /k "cd /d "%WEB_DIR%" && "%PHP_EXE%" artisan serve --host=127.0.0.1 --port=8000"

echo [START] Iniciando Ponte TCP em 127.0.0.1:2000 ...
start "LTR701 - 2. Ponte TCP (Porta 2000)" cmd /k "cd /d "%WEB_DIR%" && "%PHP_EXE%" tcp_bridge.php"

:: Aguarda a ponte TCP abrir a porta 2000 antes do MATLAB conectar
timeout /t 3 /nobreak >nul

:: 6. Abrir o navegador direto na tela de login
echo [WEB] Abrindo navegador em http://127.0.0.1:8000/login ...
start "" "http://127.0.0.1:8000/login"

if "%MODO%"=="3" goto :FIM

:: 7. Localizar automaticamente a versao mais recente do MATLAB instalada
set "MATLAB_EXE="
for /f "delims=" %%D in ('dir /b /ad /o-n "C:\Program Files\MATLAB\R*" 2^>nul') do (
    if not defined MATLAB_EXE if exist "C:\Program Files\MATLAB\%%D\bin\matlab.exe" (
        set "MATLAB_EXE=C:\Program Files\MATLAB\%%D\bin\matlab.exe"
    )
)

if not defined MATLAB_EXE (
    echo [AVISO] MATLAB nao encontrado em C:\Program Files\MATLAB\.
    echo         Abra o MATLAB manualmente e execute o script em: %MATLAB_DIR%
    goto :FIM
)
echo [OK] MATLAB detectado: %MATLAB_EXE%

if "%MODO%"=="1" (
    echo [MATLAB] Iniciando controlador REAL ^(temperature_client_node^)...
    start "LTR701 - 3. MATLAB Planta Real" "%MATLAB_EXE%" -nosplash -nodesktop -sd "%MATLAB_DIR%" -r "temperature_client_node"
) else (
    echo [MATLAB] Iniciando SIMULADOR externo ^(temperature_simulator_node^)...
    start "LTR701 - 3. MATLAB Simulador" "%MATLAB_EXE%" -nosplash -nodesktop -sd "%MATLAB_DIR%" -r "temperature_simulator_node"
)

:FIM
echo.
echo ========================================================================
echo   SISTEMA INICIADO COM SUCESSO!
echo   Login Admin   : admin@unesp.br    / 12345678
echo   Login Operador: operador@unesp.br / 12345678
echo   IMPORTANTE    : Para encerrar na bancada, aperte Ctrl+C no MATLAB!
echo ========================================================================
pause
```

#### `parar_ltr701.bat`

```bat
@echo off
title LTR701 - Encerramento de Servicos
echo ========================================================================
echo   ATENCAO: Antes de prosseguir, clique em DESLIGAR no site e pressione
echo            Ctrl+C na janela do MATLAB para zerar AO0 e AO1 com seguranca!
echo ========================================================================
pause

echo [STOP] Encerrando janelas do Laravel e da Ponte TCP...
taskkill /FI "WINDOWTITLE eq LTR701 - 1. Site Laravel*" /T /F >nul 2>nul
taskkill /FI "WINDOWTITLE eq LTR701 - 2. Ponte TCP*" /T /F >nul 2>nul

echo [OK] Portas 8000 e 2000 liberadas.
pause
```

### Opção B — Prompt Único no PowerShell (Sem Criar `.bat`)

Cole na raiz do projeto trocando `$scriptMatlab` conforme o cenário:

```powershell
$scriptMatlab = "temperature_client_node"   # Use "temperature_simulator_node" fora do laboratorio
$root   = (Get-Location).Path
$webDir = Join-Path $root "web"
$matDir = Join-Path $root "hardware\matlab"
$php    = if (Get-Command php -ErrorAction SilentlyContinue) { "php" } else { "C:\xampp\php\php.exe" }

Remove-Item "$webDir\storage\app\ltr701_*.json" -ErrorAction SilentlyContinue
& $php "$webDir\artisan" optimize:clear

Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$webDir'; & '$php' artisan serve --host=127.0.0.1 --port=8000"
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$webDir'; & '$php' tcp_bridge.php"
Start-Sleep -Seconds 3
Start-Process "http://127.0.0.1:8000/login"

$matlab = Get-ChildItem "C:\Program Files\MATLAB\R*\bin\matlab.exe" -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1
if ($matlab) {
    Start-Process $matlab.FullName -ArgumentList "-nosplash", "-nodesktop", "-sd", "`"$matDir`"", "-r", $scriptMatlab
} else {
    Write-Warning "MATLAB nao encontrado automaticamente. Execute '$scriptMatlab' manualmente em $matDir"
}
```

### Opção C — Prompt Automático para Agente no VS Code (Copilot / Cursor / Claude Code)

Se abrir o projeto no VS Code de outro computador usando um assistente de código com acesso ao terminal, cole este prompt:

```text
Prepare e inicie automaticamente o projeto Supervisório ELWE LTR701 neste computador Windows:
1. Verifique se PHP (PATH ou C:\xampp\php\php.exe) e MySQL (porta 3306) estão ativos; se o banco 'projeto_integrador' não existir, crie-o no MySQL.
2. Em web/.env, garanta DB_CONNECTION=mysql, DB_DATABASE=projeto_integrador, DB_USERNAME=root, DB_PASSWORD=, APP_URL=http://127.0.0.1:8000 e SESSION_DRIVER=database.
3. Em web/, se não existir vendor/, execute 'composer install'; remova storage/app/ltr701_*.json; execute 'php artisan migrate:fresh --seed' e 'php artisan optimize:clear'.
4. Crie o arquivo iniciar_ltr701.bat na raiz (conforme o README.md) se ainda não existir e inicie em terminais separados:
   - Terminal 1 (web/): php artisan serve --host=127.0.0.1 --port=8000
   - Terminal 2 (web/): php tcp_bridge.php
5. Localize o matlab.exe em C:\Program Files\MATLAB\R*\bin\matlab.exe e informe o comando pronto para rodar temperature_client_node (bancada real) ou temperature_simulator_node (simulador externo).
6. Confirme que http://127.0.0.1:8000/login responde HTTP 200 e que a porta TCP 2000 está em LISTEN.
```

### Opção D — Inicialização Manual (3 Terminais)

1. **Terminal 1 (`web/`):** `php artisan serve --host=127.0.0.1 --port=8000`
2. **Terminal 2 (`web/`):** `php tcp_bridge.php` *(aguarde `[TCP] Ponte LTR701 ouvindo em 127.0.0.1:2000`)*
3. **MATLAB:** abra `hardware/matlab/temperature_client_node.m` (bancada real) ou `temperature_simulator_node.m` (simulador) e clique em **Run**.
4. **Navegador:** acesse `http://127.0.0.1:8000/login`.

## 6. Credenciais, Operação Segura e Validação

### 6.1. Credenciais Padrão (`DatabaseSeeder`)

| Perfil (RBAC) | E-mail | Senha | Permissões |
| :--- | :--- | :--- | :--- |
| **Administrador** | `admin@unesp.br` | `12345678` | Controle total, CSV completo do banco e **Logs de Auditoria** (`/panel/logs`). |
| **Operador (Cliente)** | `operador@unesp.br` | `12345678` | Monitoramento, comandos e CSV completo; **bloqueado com HTTP `403`** em `/panel/logs`. |

### 6.2. Sequência Segura na Bancada

1. **Antes de ligar:** confirme os 5 cabos na NI-USB-6003 (especialmente `AI GND` e `AO GND`), válvula de entrada aberta e chaves **ACTUATOR 1/2** em **`extern`**.
2. **Ligar:** energize o Kit LTR701, pressione **ENTER** no visor frontal do kit e execute `iniciar_ltr701.bat` escolhendo a opção `[1]`.
3. **Desligar (ordem obrigatória):**
   * Clique em **DESLIGAR** no site e confirme `FLAG_CTRL=0 | FLAG_FAN=0 | Aq(AO0)=0.00V | Fan(AO1)=0.00V` no MATLAB.
   * Pressione **`Ctrl + C`** na janela do MATLAB e confirme `[CLEANUP] NI-USB-6003 zerada e liberada!`.
   * Desligue o Kit LTR701 e, por fim, execute `parar_ltr701.bat`.

### 6.3. Checklist de Validação Rápida (SCOM + LCON-I)

* [ ] **Proteção de sessão:** abrir `http://127.0.0.1:8000` sem login redireciona para `/login`.
* [ ] **RBAC (`user` vs. `admin`):** logado como `operador@unesp.br`, a tabela de logs não aparece e acessar `/panel/logs` retorna HTTP `403`.
* [ ] **Controle PI + Soprador Constante:** logado como `admin@unesp.br`, ligar controlador, variar o setpoint (ex.: $40\,^\circ\text{C} \to 60\,^\circ\text{C} \to 35\,^\circ\text{C}$) e confirmar atuação do PI em `AO 0` com o soprador mantido constante em $1{,}5\text{ V}$ em `AO 1`.
* [ ] **Desligamento por flags:** clicar em **DESLIGAR** zera aquecedor (`AO 0`) e soprador (`AO 1`) mantendo o MATLAB conectado.
* [ ] **Criptografia AES-256-CBC:** abrir `http://localhost/phpmyadmin` no banco `projeto_integrador` e comprovar que `readings.value_enc`, `commands.value_enc` e `logs.action_enc` estão cifrados (`eyJpdiI6...`).
* [ ] **Exportação completa:** clicar em **CSV Completo (BD)** e conferir que o arquivo baixado traz todo o ensaio (mais de 80 linhas) com todas as variações de setpoint, `u_temp_v`, `u_fan_v` e flags.

## 7. Diagnóstico Rápido (Solução de Problemas)

| Problema / Erro | Causa Provável | Solução |
| :--- | :--- | :--- |
| `'php' não é reconhecido como comando` | `C:\xampp\php` não está no PATH do Windows. | Use `iniciar_ltr701.bat` ou chame `C:\xampp\php\php.exe`. |
| `SQLSTATE[HY000] [2002]` ou `Unknown database` | MySQL desligado ou banco `projeto_integrador` não criado. | Inicie o MySQL no XAMPP, crie o banco (Seção 3, Passo 2) e rode `migrate:fresh --seed`. |
| Erro `419` ou volta para `/login` ao mover slider | Navegador aberto em `localhost` enquanto `APP_URL` está em `127.0.0.1`. | Acesse sempre `http://127.0.0.1:8000` e rode `php artisan optimize:clear`. |
| MATLAB falha em `tcpclient("127.0.0.1", 2000)` | `web/tcp_bridge.php` não está rodando ou fechou por erro de banco. | Abra o Terminal 2 e confirme `[TCP] Ponte LTR701 ouvindo em 127.0.0.1:2000`. |
| `Dev1` não encontrado no MATLAB | Windows atribuiu outro índice à NI-USB-6003 (ex.: `Dev2`/`Dev3`). | Rode `daqlist("ni")` no MATLAB e atualize `dev_name` em `temperature_client_node.m`. |
| Porta `8000` ou `2000` já em uso | Instância anterior do Laravel ou da ponte ficou aberta. | Execute `parar_ltr701.bat` (ou feche os CMDs antigos) e inicie de novo. |
| Site mostra ligado, mas o kit não reage | Chaves `ACTUATOR 1/2` fora de `extern` ou fio `AO GND` solto. | Passe ambas as chaves para `extern` e confirme a conexão de `AO GND`. |
| Temperatura estagna perto de $55\,^\circ\text{C}$ com `AO 0` em $9{,}5\text{ V}$ | Saturação térmica para a vazão configurada (`uFanConst`). | Reduza `uFanConst` no MATLAB (ex.: de $2{,}5\text{ V}$ para $1{,}5\text{ V}$) ou use setpoints dentro da faixa útil. |