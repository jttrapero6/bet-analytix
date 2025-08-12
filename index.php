<?php
    ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bet Analytix - Javier TT</title>
    <!-- Incluir Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Incluir Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://kit.fontawesome.com/4babbdc730.js" crossorigin="anonymous"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        #table {
            max-height: 385px;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-7xl"> <!-- Aumentado el max-w para acomodar dos columnas -->
        <?php
        // Configuración de la base de datos (asegúrate de que coincidan con tu docker-compose.yml)
        $servername = "db";
        $username = "mi_user";
        $password = "tu_password_user";
        $dbname = "mi_database";

        $conn = null; // Inicializar la conexión a null

        $edit_bet_data = null; // Variable para almacenar los datos de la apuesta a editar
        $form_action_button_name = "add_bet"; // Nombre del botón del formulario (añadir por defecto)
        $form_action_button_text = "Añadir Apuesta"; // Texto del botón del formulario

        try {
            // Conexión a la base de datos
            $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Crear la tabla 'bets' si no existe
            // Se añade la columna 'bet_name' para el nombre de la apuesta.
            $sql_create_table = "
            CREATE TABLE IF NOT EXISTS bets (
                id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bet_date DATE NOT NULL,
                sport VARCHAR(50) NOT NULL,
                bet_name VARCHAR(255) NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                odds DECIMAL(10, 2) NOT NULL,
                result ENUM('win', 'loss', 'pending', 'refund', 'cashout') DEFAULT 'pending',
                profit_loss DECIMAL(10, 2) DEFAULT 0.00,
                cashout_amount DECIMAL(10, 2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $conn->exec($sql_create_table);

            // Añadir la columna 'created_at' si no existe en una tabla preexistente
            $sql_add_created_at_column = "
            ALTER TABLE bets
            ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ";
            try {
                $conn->exec($sql_add_created_at_column);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }
            
            // Añadir la columna 'bet_name' si no existe en una tabla preexistente
            $sql_add_bet_name_column = "
            ALTER TABLE bets
            ADD COLUMN bet_name VARCHAR(255) NOT NULL
            ";
            try {
                $conn->exec($sql_add_bet_name_column);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }


            // --- Configuración para el balance inicial ---
            // Crear la tabla 'settings' si no existe
            $sql_create_settings_table = "
            CREATE TABLE IF NOT EXISTS settings (
                id INT(1) PRIMARY KEY DEFAULT 1,
                initial_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00
            )";
            $conn->exec($sql_create_settings_table);

            // Insertar una fila por defecto si no existe (para el balance inicial)
            $sql_insert_default_setting = "INSERT IGNORE INTO settings (id, initial_balance) VALUES (1, 0.00)";
            $conn->exec($sql_insert_default_setting);

            $initial_balance = 0.00; // Valor por defecto
            $show_initial_balance_form = false;

            // Lógica para establecer el balance inicial
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["set_initial_balance"])) {
                $new_initial_balance = floatval($_POST["initial_balance_amount"]);
                $stmt = $conn->prepare("UPDATE settings SET initial_balance = :initial_balance WHERE id = 1");
                $stmt->bindParam(':initial_balance', $new_initial_balance);
                $stmt->execute();
                header("Location: index.php");
                exit();
            }

            // Obtener el balance inicial actual
            $stmt_balance = $conn->prepare("SELECT initial_balance FROM settings WHERE id = 1");
            $stmt_balance->execute();
            $current_initial_balance_row = $stmt_balance->fetch(PDO::FETCH_ASSOC);
            if ($current_initial_balance_row) {
                $initial_balance = floatval($current_initial_balance_row['initial_balance']);
            }

            // Determinar si mostrar el formulario de balance inicial
            $stmt_check_bets = $conn->prepare("SELECT COUNT(*) FROM bets");
            $stmt_check_bets->execute();
            $num_bets = $stmt_check_bets->fetchColumn();

            if ($num_bets == 0 && $initial_balance == 0.00) {
                $show_initial_balance_form = true;
            }
            // --- Fin de la configuración del balance inicial ---


            // Lógica para añadir una nueva apuesta
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["add_bet"])) {
                $stmt_check_bets_on_add = $conn->prepare("SELECT COUNT(*) FROM bets");
                $stmt_check_bets_on_add->execute();
                $num_bets_on_add = $stmt_check_bets_on_add->fetchColumn();

                $stmt_balance_on_add = $conn->prepare("SELECT initial_balance FROM settings WHERE id = 1");
                $stmt_balance_on_add->execute();
                $initial_balance_on_add_row = $stmt_balance_on_add->fetch(PDO::FETCH_ASSOC);
                $initial_balance_on_add = 0.00;
                if ($initial_balance_on_add_row) {
                    $initial_balance_on_add = floatval($initial_balance_on_add_row['initial_balance']);
                }

                if ($num_bets_on_add == 0 && $initial_balance_on_add == 0.00) {
                    header("Location: index.php?error=initial_balance_required");
                    exit();
                }

                $bet_date = $_POST["bet_date"];
                $sport = htmlspecialchars($_POST["sport"]);
                $bet_name = htmlspecialchars($_POST["bet_name"]); // Nuevo campo
                $amount = floatval($_POST["amount"]);
                $odds = floatval($_POST["odds"]);
                $result = $_POST["result"];
                $cashout_amount = isset($_POST["cashout_amount"]) ? floatval($_POST["cashout_amount"]) : 0.00;

                $profit_loss = 0;
                if ($result == 'win') {
                    $profit_loss = ($amount * $odds) - $amount;
                } elseif ($result == 'loss') {
                    $profit_loss = -$amount;
                } elseif ($result == 'refund') {
                    $profit_loss = 0;
                } elseif ($result == 'cashout') {
                    $profit_loss = $cashout_amount - $amount;
                }

                // Se añade 'bet_name' al INSERT
                $stmt = $conn->prepare("INSERT INTO bets (bet_date, sport, bet_name, amount, odds, result, profit_loss, cashout_amount) VALUES (:bet_date, :sport, :bet_name, :amount, :odds, :result, :profit_loss, :cashout_amount)");
                $stmt->bindParam(':bet_date', $bet_date);
                $stmt->bindParam(':sport', $sport);
                $stmt->bindParam(':bet_name', $bet_name);
                $stmt->bindParam(':amount', $amount);
                $stmt->bindParam(':odds', $odds);
                $stmt->bindParam(':result', $result);
                $stmt->bindParam(':profit_loss', $profit_loss);
                $stmt->bindParam(':cashout_amount', $cashout_amount);

                $stmt->execute();
                header("Location: index.php");
                exit();
            }

            // Lógica para actualizar una apuesta
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_bet"])) {
                $bet_id = intval($_POST["bet_id"]);
                $bet_date = $_POST["bet_date"];
                $sport = htmlspecialchars($_POST["sport"]);
                $bet_name = htmlspecialchars($_POST["bet_name"]); // Nuevo campo
                $amount = floatval($_POST["amount"]);
                $odds = floatval($_POST["odds"]);
                $result = $_POST["result"];
                $cashout_amount = isset($_POST["cashout_amount"]) ? floatval($_POST["cashout_amount"]) : 0.00;

                $profit_loss = 0;
                if ($result == 'win') {
                    $profit_loss = ($amount * $odds) - $amount;
                } elseif ($result == 'loss') {
                    $profit_loss = -$amount;
                } elseif ($result == 'refund') {
                    $profit_loss = 0;
                } elseif ($result == 'cashout') {
                    $profit_loss = $cashout_amount - $amount;
                }

                // Se añade 'bet_name' al UPDATE
                $stmt = $conn->prepare("UPDATE bets SET bet_date = :bet_date, sport = :sport, bet_name = :bet_name, amount = :amount, odds = :odds, result = :result, profit_loss = :profit_loss, cashout_amount = :cashout_amount WHERE id = :id");
                $stmt->bindParam(':bet_date', $bet_date);
                $stmt->bindParam(':sport', $sport);
                $stmt->bindParam(':bet_name', $bet_name);
                $stmt->bindParam(':amount', $amount);
                $stmt->bindParam(':odds', $odds);
                $stmt->bindParam(':result', $result);
                $stmt->bindParam(':profit_loss', $profit_loss);
                $stmt->bindParam(':cashout_amount', $cashout_amount);
                $stmt->bindParam(':id', $bet_id);

                $stmt->execute();
                header("Location: index.php");
                exit();
            }

            // Lógica para eliminar una apuesta
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_bet"])) {
                $bet_id = intval($_POST["bet_id"]);
                $stmt = $conn->prepare("DELETE FROM bets WHERE id = :id");
                $stmt->bindParam(':id', $bet_id);
                $stmt->execute();
                header("Location: index.php");
                exit();
            }

            // Lógica para cargar datos de una apuesta para edición
            if (isset($_GET['edit_id'])) {
                $bet_id_to_edit = intval($_GET['edit_id']);
                $stmt = $conn->prepare("SELECT * FROM bets WHERE id = :id");
                $stmt->bindParam(':id', $bet_id_to_edit);
                $stmt->execute();
                $edit_bet_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($edit_bet_data) {
                    $form_action_button_name = "update_bet";
                    $form_action_button_text = "Guardar Cambios";
                }
            }

        } catch(PDOException $e) {
            echo '<p class="text-red-600 text-center mb-4">Error de conexión a la base de datos: ' . $e->getMessage() . '</p>';
        }
        ?>

        <?php if (isset($_GET['error']) && $_GET['error'] == 'initial_balance_required'): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">¡Atención!</strong>
                <span class="block sm:inline">Debes establecer tu balance inicial antes de añadir la primera apuesta.</span>
            </div>
        <?php endif; ?>

        <?php if ($show_initial_balance_form): ?>
            <div class="bg-yellow-50 p-6 rounded-lg shadow-md mb-8 text-center">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">¡Bienvenido a Bet Analytix!</h2>
                <p class="text-gray-700 mb-4">Para empezar, introduce tu **balance inicial** en euros.</p>
                <form method="POST" action="index.php" class="flex flex-col items-center gap-4">
                    <label for="initial_balance_amount" class="block text-sm font-medium text-gray-700">Balance Inicial (€):</label>
                    <input type="number" step="0.01" id="initial_balance_amount" name="initial_balance_amount" required
                        class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 text-center"
                        value="0.00">
                    <button type="submit" name="set_initial_balance" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        Establecer Balance Inicial
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div>
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">
                    <?php echo ($edit_bet_data ? 'Editar Apuesta' : 'Nueva Apuesta'); ?>
                </h2>
                <form method="POST" action="index.php" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8 p-4 border border-gray-200 rounded-lg bg-gray-50">
                    <?php if ($edit_bet_data): ?>
                        <!-- Campo oculto para el ID de la apuesta cuando estamos editando -->
                        <input type="hidden" name="bet_id" value="<?php echo htmlspecialchars($edit_bet_data['id']); ?>">
                    <?php endif; ?>
                    <div>
                        <label for="bet_date" class="block text-sm font-medium text-gray-700">Fecha:</label>
                        <input type="date" id="bet_date" name="bet_date" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2"
                            value="<?php echo $edit_bet_data ? htmlspecialchars($edit_bet_data['bet_date']) : date('Y-m-d'); ?>">
                    </div>
                    <div>
                        <label for="sport" class="block text-sm font-medium text-gray-700">Deporte:</label>
                        <select id="sport" name="sport" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2">
                            <?php
                            $sports = ['Fútbol', 'Tenis', 'Baloncesto', 'Balonmano', 'Voleibol', 'Béisbol', 'Hockey', 'Fórmula 1', 'Ciclismo'];
                            $selected_sport = $edit_bet_data ? $edit_bet_data['sport'] : '';

                            foreach ($sports as $sport) {
                                $selected = ($sport === $selected_sport) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($sport) . "\" $selected>" . htmlspecialchars($sport) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <!-- Campo para el nombre de la apuesta (nuevo, ocupa 100% de ancho) -->
                    <div class="md:col-span-2">
                        <label for="bet_name" class="block text-sm font-medium text-gray-700">Nombre de la Apuesta:</label>
                        <input type="text" id="bet_name" name="bet_name" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2"
                            value="<?php echo $edit_bet_data ? htmlspecialchars($edit_bet_data['bet_name']) : ''; ?>">
                    </div>
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Cantidad Apostada (€):</label>
                        <input type="number" step="0.01" id="amount" name="amount" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2" value="<?php echo $edit_bet_data ? htmlspecialchars($edit_bet_data['amount']) : ''; ?>">
                    </div>
                    <div>
                        <label for="odds" class="block text-sm font-medium text-gray-700">Cuota:</label>
                        <input type="number" step="0.01" id="odds" name="odds" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2" value="<?php echo $edit_bet_data ? htmlspecialchars($edit_bet_data['odds']) : ''; ?>">
                    </div>
                    <div class="md:col-span-2">
                        <label for="result" class="block text-sm font-medium text-gray-700">Resultado:</label>
                        <select id="result" name="result" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2">
                            <option value="pending" <?php echo ($edit_bet_data && $edit_bet_data['result'] == 'pending') ? 'selected' : ''; ?>>Pendiente</option>
                            <option value="win" <?php echo ($edit_bet_data && $edit_bet_data['result'] == 'win') ? 'selected' : ''; ?>>Ganada</option>
                            <option value="loss" <?php echo ($edit_bet_data && $edit_bet_data['result'] == 'loss') ? 'selected' : ''; ?>>Perdida</option>
                            <option value="refund" <?php echo ($edit_bet_data && $edit_bet_data['result'] == 'refund') ? 'selected' : ''; ?>>Reembolsada</option>
                            <option value="cashout" <?php echo ($edit_bet_data && $edit_bet_data['result'] == 'cashout') ? 'selected' : ''; ?>>Cashout</option>
                        </select>
                    </div>
                    <!-- Campo para Cashout Amount (oculto por defecto) -->
                    <div id="cashout-field" class="md:col-span-2 <?php echo ($edit_bet_data && $edit_bet_data['result'] == 'cashout') ? '' : 'hidden'; ?>">
                        <label for="cashout_amount" class="block text-sm font-medium text-gray-700">Cantidad del Cashout (€):</label>
                        <input type="number" step="0.01" id="cashout_amount" name="cashout_amount"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2"
                            value="<?php echo ($edit_bet_data && $edit_bet_data['result'] == 'cashout') ? htmlspecialchars($edit_bet_data['cashout_amount']) : ''; ?>">
                    </div>
                    <div class="md:col-span-2 flex justify-center items-center gap-4">
                        <button type="submit" name="<?php echo $form_action_button_name; ?>" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <?php echo $form_action_button_text; ?>
                        </button>
                        <?php if ($edit_bet_data): ?>
                            <a href="index.php" class="inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Cancelar Edición
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php
                // Mostrar estadísticas globales
                if ($conn) {
                    // Obtener todas las apuestas para la tabla (orden descendente por fecha de creación)
                    $stmt = $conn->prepare("SELECT * FROM bets ORDER BY created_at DESC"); // Ordenado por created_at
                    $stmt->execute();
                    $bets = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Calcular estadísticas para la tabla
                    $total_bets = count($bets);
                    $total_profit_loss = 0;
                    $total_amount_staked = 0;

                    foreach ($bets as $bet) {
                        $total_profit_loss += $bet['profit_loss'];
                        $total_amount_staked += $bet['amount'];
                    }

                    // Se ha eliminado el cálculo de ROI
                    $current_balance = $initial_balance + $total_profit_loss; // Cálculo del balance actual
                ?>

                <h2 class="text-2xl font-semibold text-gray-700 mb-4">Estadísticas Globales</h2>
                <div class="bg-gray-50 p-4 rounded-lg shadow-sm mb-8 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4"> <!-- Ajustado a 4 columnas -->
                    <div class="text-center">
                        <p class="text-sm font-medium text-gray-600">Total Apuestas:</p>
                        <p class="text-xl font-bold text-indigo-700"><?php echo $total_bets; ?></p>
                    </div>
                    <!-- Nuevo campo para el balance inicial -->
                    <div class="text-center">
                        <p class="text-sm font-medium text-gray-600">Balance Inicial:</p>
                        <p class="text-xl font-bold text-gray-700"><?php echo number_format($initial_balance, 2); ?> €</p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm font-medium text-gray-600">Total +/-:</p>
                        <p class="text-xl font-bold <?php echo $total_profit_loss >= 0 ? 'text-green-600' : 'text-red-600'; ?>"><?php echo number_format($total_profit_loss, 2); ?> €</p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm font-medium text-gray-600">Balance Actual:</p>
                        <p class="text-xl font-bold <?php echo $current_balance >= 0 ? 'text-green-600' : 'text-red-600'; ?>"><?php echo number_format($current_balance, 2); ?> €</p>
                    </div>
                </div>
                <?php } // Fin del if ($conn) para estadísticas ?>
            </div>

            <div>
                <?php
                if ($conn && $total_bets > 0) {
                    $stmt_daily_profit = $conn->prepare("SELECT bet_date, SUM(profit_loss) as daily_profit FROM bets GROUP BY bet_date ORDER BY bet_date ASC");
                    $stmt_daily_profit->execute();
                    $daily_data = $stmt_daily_profit->fetchAll(PDO::FETCH_ASSOC);

                    // Preparar los datos para la gráfica
                    $dates = [];
                    $cumulative_balance = $initial_balance; // Empezamos con el balance inicial
                    $cumulative_balance_data = [];

                    foreach ($daily_data as $row) {
                        // Se formatea la fecha para el gráfico en el formato 'DD-MM-YYYY'
                        $date_obj = new DateTime($row['bet_date']);
                        $dates[] = $date_obj->format('d-m-Y');

                        $cumulative_balance += floatval($row['daily_profit']);
                        $cumulative_balance_data[] = $cumulative_balance;
                    }

                    // Convertir los arrays de PHP a JSON para pasarlos a JavaScript
                    $json_dates = json_encode($dates);
                    $json_cumulative_balance = json_encode($cumulative_balance_data);
                ?>
                    <h2 class="text-2xl font-semibold text-gray-700 mb-4">Evolución del Balance</h2>
                    <div class="bg-gray-50 p-4 rounded-lg shadow-sm mb-8">
                        <canvas id="profitChart"></canvas>
                    </div>

                    <script>
                        // Datos de la gráfica desde PHP
                        const dates = <?php echo $json_dates; ?>;
                        const cumulativeBalance = <?php echo $json_cumulative_balance; ?>;

                        // Crear la gráfica de línea con interpolación
                        const ctx = document.getElementById('profitChart').getContext('2d');
                        const profitChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: dates,
                                datasets: [{
                                    label: 'Balance Acumulado',
                                    data: cumulativeBalance,
                                    borderColor: 'rgb(255, 99, 132)',
                                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                    tension: 0.4, // Se ha aumentado la tensión para una línea más suave
                                    fill: true
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'top',
                                    },
                                    title: {
                                        display: true,
                                        text: 'Evolución del Balance Acumulado'
                                    }
                                },
                                scales: {
                                    x: {
                                        title: {
                                            display: true,
                                            text: 'Fecha'
                                        }
                                    },
                                    y: {
                                        title: {
                                            display: true,
                                            text: 'Euros (€)'
                                        }
                                    }
                                }
                            }
                        });
                    </script>
                <?php } ?>

                <h2 class="text-2xl font-semibold text-gray-700 mb-4">Balance de Apuestas</h2>
                <?php if ($conn && $total_bets > 0): // Asegurarse de que $conn existe y hay apuestas ?>
                    <div id="table" class="overflow-x-auto">
                        <table class="min-w-full bg-white border border-gray-200 rounded-lg shadow-sm">
                            <thead>
                                <tr class="bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    <th class="py-3 px-4 border-b">Resultado</th>
                                    <th class="py-3 px-4 border-b">Fecha</th>
                                    <th class="py-3 px-4 border-b">Nombre</th> <!-- Nuevo encabezado de columna -->
                                    <th class="py-3 px-4 border-b">Deporte</th>
                                    <th class="py-3 px-4 border-b">Cantidad</th>
                                    <th class="py-3 px-4 border-b">Cuota</th>
                                    <th class="py-3 px-4 border-b">Ganancia/Pérdida</th>
                                    <th class="py-3 px-4 border-b">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($bets as $bet): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php 
                                                    if ($bet['result'] == 'win') echo 'bg-green-100 text-green-800';
                                                    elseif ($bet['result'] == 'loss') echo 'bg-red-100 text-red-800';
                                                    elseif ($bet['result'] == 'refund') echo 'bg-blue-100 text-blue-800';
                                                    elseif ($bet['result'] == 'cashout') echo 'bg-purple-100 text-purple-800';
                                                    else echo 'bg-yellow-100 text-yellow-800';
                                                ?>">
                                                <?php echo ucfirst($bet['result']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800">
                                            <?php 
                                                $date_obj = new DateTime($bet['bet_date']);
                                                echo $date_obj->format('d-m-Y');
                                            ?>
                                        </td>
                                        <!-- Nueva columna para el nombre de la apuesta -->
                                        <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800"><?php echo htmlspecialchars($bet['bet_name']); ?></td>
                                        <td class="py-3 px-4 whitespace-nowrap text-m text-gray-800 text-center">
                                            <?php if ($bet['sport'] == "Fútbol") : ?>
                                                <i class="fa-solid fa-futbol"></i>
                                            <?php elseif ($bet['sport'] == "Tenis") : ?>
                                                <i class="fa-solid fa-baseball"></i>
                                            <?php else : ?>
                                                <?php echo htmlspecialchars($bet['sport']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800"><?php echo number_format($bet['amount'], 2); ?> €</td>
                                        <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800"><?php echo number_format($bet['odds'], 2); ?></td>
                                        <td class="py-3 px-4 whitespace-nowrap text-sm font-medium 
                                            <?php echo $bet['profit_loss'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                            <?php echo number_format($bet['profit_loss'], 2); ?> €
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap text-sm font-medium flex items-center space-x-2">
                                            <!-- Botón de Editar -->
                                            <a href="index.php?edit_id=<?php echo $bet['id']; ?>" class="text-indigo-600 hover:text-indigo-900">Editar</a>
                                            <!-- Formulario de Eliminar -->
                                            <form method="POST" action="index.php" onsubmit="return confirm('¿Estás seguro de que quieres eliminar esta apuesta?');" class="inline-block">
                                                <input type="hidden" name="bet_id" value="<?php echo $bet['id']; ?>">
                                                <button type="submit" name="delete_bet" class="text-red-600 hover:text-red-900">
                                                    Eliminar
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-gray-600">No hay apuestas registradas todavía. ¡Añade una!</p>
                <?php endif; ?>
            </div>
        </div> <!-- Fin del contenedor principal de dos columnas -->

    </div>
    <script>
        // Lógica para mostrar/ocultar el campo de cashout
        const resultSelect = document.getElementById('result');
        const cashoutField = document.getElementById('cashout-field');
        const cashoutAmountInput = document.getElementById('cashout_amount');
        
        // Función para manejar la visibilidad y el atributo 'required'
        function toggleCashoutField() {
            if (resultSelect.value === 'cashout') {
                cashoutField.classList.remove('hidden');
                cashoutAmountInput.setAttribute('required', 'true');
            } else {
                cashoutField.classList.add('hidden');
                cashoutAmountInput.removeAttribute('required');
                // Opcional: limpiar el valor cuando se oculta
                // cashoutAmountInput.value = ''; 
            }
        }

        // Ejecutar al cargar la página (para el caso de edición)
        document.addEventListener('DOMContentLoaded', toggleCashoutField);

        // Ejecutar cada vez que cambia la selección
        resultSelect.addEventListener('change', toggleCashoutField);
    </script>
</body>
</html>
<?php
        ob_end_flush();
?>
