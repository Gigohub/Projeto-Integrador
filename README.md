# Projeto Integrador (SCOM + LCON-I) — Supervisório e Sombra Digital do Kit ELWE LTR701

**Instituição:** UNESP — Câmpus de Sorocaba (ICT)  
**Disciplinas:** Sistemas Computacionais (SCOM) e Laboratório de Controle I (LCON-I)  
**Integrantes:**
* Caio Gabriel Pereira de Morais — RA: `241270626`
* João Augusto Oliveira Candido da Silva — RA: `241270847`
* Lucas Yuta Ameda — RA: `241270618`
* Pedro Henrique de Melo Rosa — RA: `241270031`

---

## 1. Visão Geral do Projeto

Este sistema é uma plataforma web completa de **monitoramento, controle remoto em malha fechada e simulação (Sombra Digital)** para o soprador térmico didático **ELWE LTR701** (utilizado nas aulas de LCON-I, especificamente o **Kit 001**).

Ele integra os requisitos das duas disciplinas em uma única aplicação:
* **Lado SCOM:** backend RESTful seguro em **Laravel (PHP 8.3+)** com banco de dados relacional (**MySQL/SQLite**), autenticação com controle de acesso por perfil (`admin` e `user`), criptografia **AES-256-CBC** para leituras, comandos e logs de auditoria, além de dashboard em tempo real.
* **Lado LCON-I:** controle digital **Proporcional-Integral (PI)** a $100\text{ Hz}$ ($T_s = 0{,}01\text{ s}$) para **Vazão** ($\text{kg/h}$) e **Temperatura** ($^\circ\text{C}$), filtro passa-baixas digital ($f_c = 12{,}5\text{ Hz}$), curvas experimentais de calibração dos sensores e uma **Sombra Digital isolada** para ensaios sem risco à planta física.

---

## 2. Como o Sistema Funciona (Arquitetura)

O fluxo de dados conecta a bancada física (via **MATLAB + NI-USB-6003** ou via **ESP32 + Circuito de Condicionamento**) ao servidor Laravel e à interface web no navegador:

```text
[Kit ELWE LTR701] <==(0..10 V)==> [NI-USB-6003 + MATLAB] ou [Condicionador + ESP32]
                                                |
                         HTTP REST (Wi-Fi) ou Web Serial (USB 115200 baud)
                                                v
                           [Backend Laravel (web/) + MySQL (AES-256-CBC)]
                                                |
                                                v
                 [Dashboard Web: Planta Real | Sombra Digital | Diagrama SVG]
```

### 2.1. Estrutura de Arquivos Principais

| Caminho | Responsabilidade |
| :--- | :--- |
| `web/routes/api.php` | Endpoints consumidos pelo hardware (`POST /api/readings`, `GET /api/commands`, `POST /api/commands/ack`), protegidos pelo middleware `device.token`. |
| `web/routes/web.php` | Rotas de login/cadastro e endpoints internos do painel (`/panel/history`, `/panel/send`, `/panel/simulate`, `/panel/logs`). |
| `web/app/Models/` | Models Eloquent (`Reading`, `Command`, `AuditLog`, `User`) que criptografam automaticamente os dados sensíveis antes de gravar no banco e descriptografam apenas na exibição autenticada. |
| `web/app/Http/Middleware/` | `VerifyDeviceToken.php` (valida o cabeçalho `Authorization: Bearer <TOKEN>`) e `EnsureAdmin.php` (bloqueia rotas administrativas para usuários comuns). |
| `web/app/Http/Controllers/AuthController.php` | Login, cadastro de operadores, logout e registro de auditoria. |
| `web/app/Http/Controllers/TelemetryController.php` | Recepção de telemetria, conversão via parser matemático seguro das equações de calibração, fila de comandos, detecção de placas Wi-Fi (heartbeat) e cálculo de grandezas físicas. |
| `web/resources/views/dashboard.blade.php` | Interface completa com Bootstrap 5, Alpine.js, Chart.js, Web Serial API, Sombra Digital acoplada e Diagrama de Blocos SVG interativo. |
| `hardware/esp/ESP.ino` | Firmware base do ESP32 para leitura de sensores e comunicação HTTP com a API. |
| `hardware/matlab/` | Scripts de aquisição/comunicação em MATLAB. |

### 2.2. Separação Visual e Lógica das Duas Abas

Para evitar que testes de sintonia afetem a bancada real (requisito explícito de LCON-I), a interface possui duas abas com layout clonado, porém totalmente isoladas:

1. **Aba 1 — Planta Real (Faixa Verde):**
   * Possui **seletor de placa/porta estilo Arduino IDE**: detecta automaticamente placas na rede Wi-Fi que chamam a API (exibindo IP e status Online/Offline) e permite conectar placas diretamente via cabo USB pela **Web Serial API** (Chrome/Edge, `115200 baud`, com Monitor Serial integrado).
   * Os sliders de Setpoint, $K_p$ e $K_i$ enviam comandos diretamente para a fila da placa selecionada (com *debounce* de $280\text{ ms}$) e gravam logs criptografados no banco.
2. **Aba 2 — Sombra Digital (Faixa Roxa):**
   * Clone exato da aba real rodando **100% no navegador**, sem enviar nenhum comando ao hardware nem gravar nada no MySQL.
   * Permite injetar perturbações na válvula ou na temperatura, abrir a gaveta de configuração da planta $G(s)$ e rodar o ensaio determinístico em degrau (discretização bilinear/Tustin a $100\text{ Hz}$).
3. **Taxas de Atualização e Estabilidade Visual:**
   * Os **3 gráficos** (Unificado de interferência cruzada + Vazão individual + Temperatura individual) atualizam a **$2\text{ Hz}$** ($2\text{ pontos/s}$) para resposta fluida.
   * Os **6 cards numéricos** atualizam a **$1\text{ Hz}$** ($1\text{ vez/s}$) com arredondamento fixo de **3 casas decimais** e largura tabular fixa para evitar trepidação horizontal dos dígitos.
4. **Diagrama de Blocos SVG Interativo:**
   * Fica abaixo dos gráficos, reflete ao vivo os valores da aba e da malha selecionada e exibe explicações de diagnóstico ao clicar em qualquer bloco.

---

## 3. De Onde Foram Retiradas as Informações e Equações

Todos os parâmetros pré-carregados no supervisório foram extraídos diretamente das atividades práticas de **LCON-I** realizadas pelo grupo no **Kit ELWE LTR701 (Kit 001)**:

### 3.1. Calibração dos Sensores ($V \to \text{Grandeza Física}$)

| Sensor do Kit LTR701 | Origem | Equação Obtida pelo Grupo | Observação |
| :--- | :--- | :--- | :--- |
| **SENSOR 2** (Temperatura — Termopar Tipo K) | Atividade 07 (Parte A) | $T(x) = 10{,}4x + 0{,}0795$ ($^\circ\text{C}$) | Requer filtro passa-baixas digital com $f_c = 12{,}5\text{ Hz}$ antes do cálculo do erro. |
| **SENSOR 5** (Vazão Mássica — *Air Mass Meter*) | Atividade 10 (Parte A) | $Q(x) = 3{,}94\,e^{0{,}617x}$ ($\text{kg/h}$) | Relação exponencial não-linear; substitui o polinômio genérico do roteiro. |

> No painel web, ambas as equações podem ser editadas em tempo real em função de `x` (tensão em Volts de $0\text{ a }10\text{ V}$) e são avaliadas por um parser matemático seguro no backend quando o hardware envia tensões brutas (`flow_v` / `temp_v`).

### 3.2. Funções de Transferência da Planta $G(s)$

| Malha | Origem | Função de Transferência | Detalhes da Identificação |
| :--- | :--- | :--- | :--- |
| **Vazão** (ACTUATOR 1 $\to$ SENSOR 5) | Atividade 10 (Partes B e C) | $G_{Q1}(s) = \dfrac{10{,}14}{s + 1{,}037}$ *(1ª ordem, fit $76{,}61\%$)*<br>$G_{Q2}(s) = \dfrac{19{,}16}{s^2 + 4{,}564s + 1{,}902}$ *(2ª ordem, fit $92{,}07\%$)* | Degrau de $10\text{ V}$ por $10\text{ s}$ a $100\text{ Hz}$ (`script01.m` + `tfest` no `script02.m`). Constante de tempo dominante $\tau_{vaz} \approx 0{,}964\text{ s}$. |
| **Temperatura** (ACTUATOR 2 $\to$ SENSOR 2) | Atividade 07 (Partes B e C) e Colab PI | Exemplo base: $G_{T}(s) = \dfrac{2{,}0}{s + 0{,}4}$ *(constante $\tau_{temp0} = 2{,}500\text{ s}$)* | Degrau de $10\text{ V}$ no aquecedor com ventilador a $3{,}0\text{ V}$ por $30\text{ s}$ a $100\text{ Hz}$ e atraso `tdelay = 1.16` s. *(Ajustável na gaveta da Sombra Digital caso o grupo rode nova identificação).* |

### 3.3. Técnica de Controle e Ganhos PI

Em ambas as malhas utiliza-se um **Controlador PI Digital** na forma paralela com período de amostragem $T_s = 0{,}01\text{ s}$ ($100\text{ Hz}$) e saturação física compatível com o kit ($0\text{ a }9{,}5\text{ V}$ / $0\text{ a }10\text{ V}$):

$$i(k) = i(k-1) + K_i \, e(k) \, T_s$$

$$u(k) = K_p \, e(k) + i(k)$$

* **Regra de segurança (requisito LCON-I):** quando a flag de habilitação (`enable`) vai para `0`, a parcela integral $i(k)$ é zerada imediatamente para evitar disparo ao religar o controle.
* **Sintonia da Vazão (Atividade 11 — Domínio da Frequência / Bode):**
  * Malha aberta: $G_m = \infty\text{ dB}$, $M_p = 93{,}1^\circ$ em $19{,}13\text{ rad/s}$.
  * Projeto adotado: $\omega_{cg} = 3\text{ rad/s}$ e $pm = 80^\circ$, resultando em $K_p = 0{,}1657$ e $K_i = 0{,}2763$.
* **Sintonia da Temperatura (Atividades 08 e 09 — *Root Locus*):**
  * Folha de respostas da Ativ. 08: $K_p = 10$ e $K_i = 2$ (ajustáveis para baixo por fator de $2$ ou $10$ caso ocorra saturação na bancada); preset conservador inicial no site: $K_p = 0{,}800$ e $K_i = 0{,}300$.

### 3.4. Física Acoplada e Variáveis Derivadas no Dashboard

Para representar a interação real entre ventilador e aquecedor dentro do duto (diâmetro interno estimado $D \approx 50\text{ mm}$, área $A \approx 1{,}9635 \times 10^{-3}\text{ m}^2$), o sistema calcula em tempo real:

* **Densidade do ar:** $\rho(T) = \dfrac{P_{atm}}{R_{ar}(T + 273{,}15)}$, com $P_{atm} = 101325\text{ Pa}$ e $R_{ar} = 287{,}05\text{ J/(kg}\cdot\text{K)}$.
* **Velocidade real do ar no duto:** $v = \dfrac{\dot{m}/3600}{\rho(T) \cdot A}$ ($\text{m/s}$).
* **Pressão estimada no tubo:** pressão manométrica $\Delta P = \frac{1}{2}\rho v^2 (1 + K_{perda})$ em $\text{Pa}$ e pressão absoluta em $\text{atm}$.
* **Acoplamento cruzado:** aumentar a vazão aumenta o coeficiente de convecção forçada $h_{eff}(Q)$, reduzindo $\tau_{temp}$ e resfriando o duto (atuando como perturbação na malha de temperatura). Ruído estocástico sutil é adicionado na simulação em regime ambiente e amplificado sob aquecimento ativo para reproduzir o comportamento do sensor real.

---

## 4. Como Subir o Sistema Localmente

### 4.1. Pré-requisitos
* PHP $8.3+$ e Composer instalados (ou ambiente XAMPP com PHP atualizado).
* Banco MySQL rodando (ou SQLite padrão configurado no `web/.env`).
* Navegador **Google Chrome** ou **Microsoft Edge** (necessário caso utilize a detecção USB via Web Serial API).

### 4.2. Passo a Passo no Terminal

```bash
# 1. Entre na pasta da aplicação Laravel
cd web

# 2. Configure o arquivo .env (defina DB_* e adicione o token do dispositivo)
# Exemplo obrigatório no web/.env:
# DEVICE_API_TOKEN=token_secreto_ltr701

# 3. Execute as migrations e popule os usuários iniciais
php artisan migrate:fresh --seed

# 4. Inicie o servidor local
php artisan serve
```

Acesse `http://127.0.0.1:8000` no navegador.

### 4.3. Credenciais Padrão (Seed)

| Perfil | E-mail | Senha | Permissões |
| :--- | :--- | :--- | :--- |
| **Administrador** | `admin@unesp.br` | `12345678` | Controle total + visualização de **Logs de Auditoria** criptografados. |
| **Operador** | `operador@unesp.br` | `12345678` | Controle da planta, telemetria, CSV e Sombra Digital (sem acesso aos logs). |

---

## 5. Como Operar no Sistema Real (Kit ELWE LTR701)

### 5.1. Cuidados Elétricos Obrigatórios (Antes de Ligar Qualquer Cabo)

Os sensores e atuadores do kit ELWE LTR701 trabalham no padrão industrial de **$0\text{ a }10\text{ V}$**. Existem duas formas de conectar o kit ao computador/site:

1. **Via Placa de Aquisição NI-USB-6003 + MATLAB (Recomendado em LCON-I):**
   * A NI-USB-6003 já opera nativamente em $0\text{ a }10\text{ V}$.
   * **Conexões (Atividades 07, 09, 10 e 11):**
     * `AI 0` e `AI GND` da NI-6003 $\to$ saída $0\text{--}10\text{ V}$ e referência ($\perp$) do **SENSOR 2** (temperatura) ou **SENSOR 5** (vazão).
     * `AO 0` da NI-6003 $\to$ entrada $0\text{--}10\text{ V}$ do **ACTUATOR 2** (aquecedor) ou **ACTUATOR 1** (ventilador).
     * `AO 1` da NI-6003 $\to$ entrada $0\text{--}10\text{ V}$ do **ACTUATOR 1** (quando o script controla temperatura mantendo o ventilador em $25\%$ / $2{,}5\text{--}3{,}0\text{ V}$).
2. **Via Microcontrolador ESP32:**
   * **ATENÇÃO — RISCO DE QUEIMA:** O ESP32 opera estritamente entre **$0\text{ e }3{,}3\text{ V}$**. **Nunca** ligue a saída de $10\text{ V}$ do kit diretamente no ADC do ESP32.
   * É obrigatório utilizar o **circuito de condicionamento de sinais** (Atividades 08 e 09):
     * **Entrada (Sensor $\to$ GPIO 34):** divisor de tensão com potenciômetro calibrado para reduzir $10\text{ V} \to 3{,}0\text{ V}$.
     * **Saída (GPIO 25 $\to$ Atuador):** amplificador não-inversor com **LM741** alimentado em $\pm 15\text{ V}$ ($R = 1\text{ k}\Omega$ + potenciômetro $R_f$) ajustado no osciloscópio para ampliar $3{,}0\text{ V} \to 10\text{ V}$, conforme $G = 1 + \frac{R_f}{R}$.

### 5.2. Sequência Segura de Operação na Bancada

1. **Inspeção inicial do Kit LTR701 (desligado):**
   * Coloque os dois seletores manuais (**ACTUATOR 1** e **ACTUATOR 2**) na posição **`off`**.
   * Coloque a válvula manual de entrada de ar na **posição vertical** (totalmente aberta).
   * Confira todas as conexões de cabos banana-jacaré e o aterramento comum (`GND`).
2. **Energização:**
   * Ligue a fonte CC ($\pm 15\text{ V}$, se estiver usando o condicionador LM741 com ESP32).
   * Ligue o kit LTR701 e pressione o botão **`ENTER`** no painel frontal para visualizar as medições ao vivo ($t_1$, $Q$, $P_H$, $P_M$).
3. **Conexão com o Supervisório Web:**
   * **Via Wi-Fi (REST API):** configure no `ESP.ino` ou no script MATLAB o endereço `http://<IP_DO_SERVIDOR>:8000/api` e o mesmo `DEVICE_API_TOKEN` definido no `web/.env`. Assim que a primeira requisição chegar em `/api/readings` ou `/api/commands`, a placa aparecerá automaticamente com bolinha verde (`● ONLINE`) no dropdown **Placa / Porta** da aba **Planta Real**.
   * **Via Cabo USB (ESP32 direto no PC):** clique em **`+ Detectar Placa USB`** na aba **Planta Real**, selecione a porta COM correspondente (CP210x, CH340 ou Espressif USB) e acompanhe os dados no **Monitor Serial** integrado (`115200 baud`).
4. **Execução do Controle e Teste de Perturbação:**
   * Na aba **Planta Real**, selecione a placa desejada no dropdown, clique em **LIGAR** na malha correspondente e arraste o slider de Setpoint (ex.: $35\,^\circ\text{C} \to 50\,^\circ\text{C}$ na temperatura ou $50\text{ kg/h} \to 80\text{ kg/h}$ na vazão).
   * Para testar a rejeição a perturbações em regime permanente, gire **parcialmente** a válvula manual de entrada de ar (**nunca feche totalmente a passagem de ar** com o aquecedor ligado) e observe o esforço de controle compensar a queda/elevação.
5. **Desligamento Seguro (Ordem Inversa):**
   * Clique em **DESLIGAR** nas malhas pelo site (zerando os sinais de controle e a parcela integral).
   * Aguarde a temperatura do aquecedor baixar por alguns segundos com ventilação ou até estabilizar.
   * Desligue o kit LTR701, reduza e desligue a fonte CC, desconecte o cabo USB/NI-6003 e só então desfaça os cabos banana-jacaré.

---

## 6. Guia Rápido de Diagnóstico (Se o Real Divergir da Simulação)

| Sintoma Observado | Causa Provável | Ação Corretiva |
| :--- | :--- | :--- |
| Valor no site difere do display do kit LTR701 por um valor fixo ou proporcional. | Equação de calibração $f(x)$ desatualizada ou potenciômetro divisor fora de escala ($10\text{ V} \to 3\text{ V}$). | Conferir `10.4*x + 0.0795` (temp.) e `3.94*exp(0.617*x)` (vazão) e medir a entrada com multímetro. |
| Atuador ($P_H$ ou $P_M$) fica travado em $100\%$ ou oscila entre $0\%$ e $100\%$. | Ganhos $K_p$ e $K_i$ altos demais para o kit real ou ganho do LM741 saturando. | Dividir $K_p$ e $K_i$ por **$2$ ou por $10$** (orientação dos Roteiros 09 e 11) e retestar. |
| Temperatura muito ruidosa ou instável. | Filtro passa-baixas digital ($12{,}5\text{ Hz}$) desabilitado no firmware/script. | Verificar se a equação a diferenças do filtro bilinear está ativa antes do erro $e(k)$. |
| Sistema dá "pico" imediato ao religar a malha. | Acumulador integral $i(k)$ não foi zerado quando `enable = 0`. | Garantir o reset de $i(k) = 0$ sempre que o controle for desabilitado. |
| Erro HTTP `401` na API do dispositivo. | `DEVICE_API_TOKEN` ausente ou diferente entre `web/.env` e ESP/MATLAB. | Conferir o token e rodar `php artisan config:clear`. |

---

## 7. Checklist de Pendências para a Entrega Final

Para fechar 100% dos entregáveis exigidos nas especificações de **SCOM** e **LCON-I**, o grupo deve concluir na sequência:
- [ ] Atualizar `hardware/esp/ESP.ino` (e/ou o script MATLAB da NI-6003) para comunicar via HTTP REST com `/api/readings`, `/api/commands` e `/api/commands/ack` sem bloquear o loop de $100\text{ Hz}$.
- [ ] Adicionar no dashboard o card de **Estatística Descritiva** das séries históricas (média, variância, curtose, assimetria e detecção de outliers) exigido em SCOM.
- [ ] Implementar o módulo administrativo de **Backup automático diário e Restauração** do banco de dados.
- [ ] Coletar e documentar no relatório técnico as métricas de rede da comunicação: **latência**, **throughput**, **perda de pacotes (%)** e **RTT (Round Trip Time)**.