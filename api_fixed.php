<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class LoginHandler {
    private $servername = 'ao657.brighton.domains';
    private $username   = 'ao657_login';
    private $password   = 'Login123!';
    private $dbname     = 'ao657_LoginHandler';
    private $conn;

    public function __construct() {
        $this->conn = new mysqli($this->servername, $this->username, $this->password, $this->dbname);
        if ($this->conn->connect_error) {
            exit(json_encode(["success" => false, "message" => "Connection failed"]));
        }
        $this->ensureColumns();
    }

    // adds any missing weight columns to ScoutPreferences
    private function ensureColumns(): void {
        $columns = [
            'weight_speed', 'weight_height', 'weight_age',
            'weight_pos_gk', 'weight_pos_def', 'weight_pos_mid', 'weight_pos_wing', 'weight_pos_str'
        ];
        $r = $this->conn->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ScoutPreferences'"
        );
        $existing = [];
        if ($r) { while ($row = $r->fetch_assoc()) $existing[] = $row['COLUMN_NAME']; }
        foreach ($columns as $col) {
            if (!in_array($col, $existing)) {
                $this->conn->query("ALTER TABLE `ScoutPreferences` ADD COLUMN `$col` FLOAT NOT NULL DEFAULT 1.0");
            }
        }
    }

    // cosine similarity between two vectors, returns 0.0–1.0
    private function cosineSimilarity(array $a, array $b): float {
        $dot = 0.0; $magA = 0.0; $magB = 0.0;
        for ($i = 0; $i < count($a); $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        $denom = sqrt($magA) * sqrt($magB);
        return $denom > 0 ? round($dot / $denom, 4) : 0.0;
    }

    // builds a 10-dimensional feature vector from a player row
    private function buildPlayerVector(array $row): array {
        return [
            (float)($row['scaled_speed']          ?? 0.5),
            (float)($row['scaled_height']         ?? 0.5),
            (float)($row['scaled_age']            ?? 0.5),
            (float)($row['scaled_weight']         ?? 0.5),
            (float)($row['scaled_goals_per_game'] ?? 0.0),
            (float)($row['pos_gk']   ?? 0),
            (float)($row['pos_def']  ?? 0),
            (float)($row['pos_mid']  ?? 0),
            (float)($row['pos_wing'] ?? 0),
            (float)($row['pos_str']  ?? 0),
        ];
    }

    public function handleRequest() {
        $data   = !empty($_POST) ? $_POST : json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';

        if ($action === 'register') {
            $hashed = password_hash($data['password'], PASSWORD_DEFAULT);
            $type   = ($data['userType'] === 'Player') ? 1 : 0;
            $stmt   = $this->conn->prepare("INSERT INTO Logins (Email, Password, Type) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $data['email'], $hashed, $type);
            return ["success" => $stmt->execute(), "message" => "Account handled"];
        }

        if ($action === 'login') {
            $type = ($data['userType'] === 'Player') ? 1 : 0;
            $stmt = $this->conn->prepare("SELECT Password FROM Logins WHERE Email = ? AND Type = ?");
            $stmt->bind_param("si", $data['email'], $type);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($user = $res->fetch_assoc()) {
                if (password_verify($data['password'], $user['Password'])) {
                    return ["success" => true, "userId" => $data['email']];
                }
            }
            return ["success" => false, "message" => "Invalid Login"];
        }

        if ($action === 'search_players') {
            $scout_email = $data['scout_email'] ?? '';
            $search      = $data['query']       ?? '';
            $pos         = $data['position']    ?? '';
            $min_age     = (int)($data['min_age']    ?? 0);
            $max_age     = (int)($data['max_age']    ?? 50);
            $min_height  = (int)($data['min_height'] ?? 0);
            $min_speed   = (int)($data['min_speed']  ?? 0);
            $foot        = $data['foot']             ?? '';

            $query  = "SELECT p.*,
                       CASE WHEN s.player_email IS NOT NULL THEN 1 ELSE 0 END as is_shortlisted
                       FROM PlayerProfiles p
                       LEFT JOIN ScoutShortlist s ON p.email = s.player_email AND s.scout_email = ?
                       WHERE (p.full_name LIKE ? OR p.previous_clubs LIKE ? OR p.nearest_town LIKE ?)";
            $params = [$scout_email, "%$search%", "%$search%", "%$search%"];
            $types  = "ssss";

            if ($pos !== '' && $pos !== '0') {
                $pos_cols = [1 => 'gk', 2 => 'def', 3 => 'mid', 4 => 'wing', 5 => 'str'];
                $col = $pos_cols[(int)$pos];
                $query .= " AND (p.pos_$col = 1 OR p.s_pos_$col = 1)";
            }
            if ($foot !== '') {
                $query  .= " AND p.preferred_foot = ?";
                $types  .= "i";
                $params[] = (int)$foot;
            }
            // only apply numeric filters when the scout has explicitly changed them
            $filters_active = filter_var($data['filters_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($filters_active) {
                $query  .= " AND (p.age BETWEEN ? AND ? OR p.age IS NULL) AND (p.height >= ? OR p.height IS NULL) AND (p.top_speed >= ? OR p.top_speed IS NULL)";
                $types  .= "iiii";
                array_push($params, $min_age, $max_age, $min_height, $min_speed);
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result  = $stmt->get_result();
            $players = [];
            while ($row = $result->fetch_assoc()) { $players[] = $row; }
            return ["success" => true, "players" => $players];
        }

        if ($action === 'get_discovery_feed') {
            $scout_email = $data['scout_email'];
            $offset      = (int)($data['offset'] ?? 0);

            // calculate epsilon based on how many interactions the scout has had
            $interactions = 0;
            $stmt_p = $this->conn->prepare(
                "SELECT interaction_count FROM ScoutPreferences WHERE scout_email = ?"
            );
            $stmt_p->bind_param("s", $scout_email);
            $stmt_p->execute();
            $p_res = $stmt_p->get_result()->fetch_assoc();
            if ($p_res) $interactions = (int)$p_res['interaction_count'];

            $epsilon        = max(0.1, 1.0 - ($interactions * 0.05));
            $is_exploration = (mt_rand() / mt_getrandmax()) < $epsilon;

            $w_speed = 1.0; $w_height = 1.0; $w_age = 1.0;
            $w_pos_gk = 1.0; $w_pos_def = 1.0; $w_pos_mid = 1.0; $w_pos_wing = 1.0; $w_pos_str = 1.0;
            $stmt_w = $this->conn->prepare(
                "SELECT weight_speed, weight_height, weight_age,
                        weight_pos_gk, weight_pos_def, weight_pos_mid, weight_pos_wing, weight_pos_str
                 FROM ScoutPreferences WHERE scout_email = ?"
            );
            $w_res = null;
            if ($stmt_w) {
                $stmt_w->bind_param("s", $scout_email);
                $stmt_w->execute();
                $w_res = $stmt_w->get_result()->fetch_assoc();
            }
            if ($w_res) {
                $w_speed    = (float)($w_res['weight_speed']  ?? 1.0);
                $w_height   = (float)($w_res['weight_height'] ?? 1.0);
                $w_age      = (float)($w_res['weight_age']    ?? 1.0);
                $w_pos_gk   = (float)($w_res['weight_pos_gk']   ?? 1.0);
                $w_pos_def  = (float)($w_res['weight_pos_def']  ?? 1.0);
                $w_pos_mid  = (float)($w_res['weight_pos_mid']  ?? 1.0);
                $w_pos_wing = (float)($w_res['weight_pos_wing'] ?? 1.0);
                $w_pos_str  = (float)($w_res['weight_pos_str']  ?? 1.0);
            }

            // build a target vector from players the scout has positively engaged with
            $v_query = "SELECT
                        IFNULL(AVG(p.scaled_age),            0.5) as avg_a,
                        IFNULL(AVG(p.scaled_height),         0.5) as avg_h,
                        IFNULL(AVG(p.scaled_speed),          0.5) as avg_s,
                        IFNULL(AVG(p.scaled_weight),         0.5) as avg_w,
                        IFNULL(AVG(p.scaled_goals_per_game), 0.0) as avg_g,
                        IFNULL(AVG(p.pos_gk),  0) as avg_gk,
                        IFNULL(AVG(p.pos_def), 0) as avg_def,
                        IFNULL(AVG(p.pos_mid), 0) as avg_mid,
                        IFNULL(AVG(p.pos_wing),0) as avg_wing,
                        IFNULL(AVG(p.pos_str), 0) as avg_str
                        FROM PlayerProfiles p
                        JOIN ScoutBehavior b ON p.email = b.player_email
                        WHERE b.scout_email = ? AND b.combined_score > 0.4";
            $stmt_v = $this->conn->prepare($v_query);
            $stmt_v->bind_param("s", $scout_email);
            $stmt_v->execute();
            $target = $stmt_v->get_result()->fetch_assoc();

            $t_age    = (float)$target['avg_a'];
            $t_height = (float)$target['avg_h'];
            $t_speed  = (float)$target['avg_s'];
            $t_weight = (float)$target['avg_w'];
            $t_gpg    = (float)$target['avg_g'];
            $t_gk     = (float)$target['avg_gk'];
            $t_def    = (float)$target['avg_def'];
            $t_mid    = (float)$target['avg_mid'];
            $t_wing   = (float)$target['avg_wing'];
            $t_str    = (float)$target['avg_str'];

            $target_vector = [
                $t_speed, $t_height, $t_age, $t_weight, $t_gpg,
                (float)$target['avg_gk'],  (float)$target['avg_def'],
                (float)$target['avg_mid'], (float)$target['avg_wing'],
                (float)$target['avg_str']
            ];

            // skip players the scout has already rated highly
            $base_join  = "LEFT JOIN ScoutBehavior b ON p.email = b.player_email AND b.scout_email = ?";
            $base_where = "WHERE (b.explicit_rating IS NULL OR b.explicit_rating < 90)";

            if ($is_exploration) {
                $q = "SELECT p.* FROM PlayerProfiles p $base_join $base_where ORDER BY RAND() LIMIT 10 OFFSET ?";
                $stmt = $this->conn->prepare($q);
                $stmt->bind_param("si", $scout_email, $offset);
            } else {
                // rank by weighted Euclidean distance to the target vector
                $q = "SELECT p.* FROM PlayerProfiles p $base_join $base_where
                      ORDER BY SQRT(
                          ? * POW(IFNULL(p.scaled_age,    0.5) - ?, 2) +
                          ? * POW(IFNULL(p.scaled_height, 0.5) - ?, 2) +
                          ? * POW(IFNULL(p.scaled_speed,  0.5) - ?, 2) +
                              POW(IFNULL(p.scaled_weight, 0.5) - ?, 2) +
                          ? * POW(IFNULL(p.pos_gk,   0) - ?, 2) +
                          ? * POW(IFNULL(p.pos_def,  0) - ?, 2) +
                          ? * POW(IFNULL(p.pos_mid,  0) - ?, 2) +
                          ? * POW(IFNULL(p.pos_wing, 0) - ?, 2) +
                          ? * POW(IFNULL(p.pos_str,  0) - ?, 2)
                      ) ASC LIMIT 10 OFFSET ?";
                $stmt = $this->conn->prepare($q);
                $stmt->bind_param("sdddddddddddddddddi",
                    $scout_email,
                    $w_age,      $t_age,
                    $w_height,   $t_height,
                    $w_speed,    $t_speed,
                                 $t_weight,
                    $w_pos_gk,   $t_gk,
                    $w_pos_def,  $t_def,
                    $w_pos_mid,  $t_mid,
                    $w_pos_wing, $t_wing,
                    $w_pos_str,  $t_str,
                    $offset
                );
            }

            $stmt->execute();
            $result     = $stmt->get_result();
            $candidates = [];
            while ($row = $result->fetch_assoc()) { $candidates[] = $row; }

            // attach distance from the centroid of liked players' locations
            $loc_q = "SELECT AVG(p.latitude) as avg_lat, AVG(p.longitude) as avg_lon
                      FROM PlayerProfiles p
                      JOIN ScoutBehavior b ON p.email = b.player_email
                      WHERE b.scout_email = ? AND b.combined_score > 0.4
                        AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL";
            $stmt_loc = $this->conn->prepare($loc_q);
            $stmt_loc->bind_param("s", $scout_email);
            $stmt_loc->execute();
            $loc_res  = $stmt_loc->get_result()->fetch_assoc();
            $avg_lat  = ($loc_res && $loc_res['avg_lat'] !== null) ? (float)$loc_res['avg_lat'] : null;
            $avg_lon  = ($loc_res && $loc_res['avg_lon'] !== null) ? (float)$loc_res['avg_lon'] : null;

            foreach ($candidates as &$player) {
                $p_lat = isset($player['latitude'])  ? (float)$player['latitude']  : null;
                $p_lon = isset($player['longitude']) ? (float)$player['longitude'] : null;
                if ($avg_lat !== null && $avg_lon !== null && $p_lat !== null && $p_lon !== null) {
                    $R    = 6371.0;
                    $dLat = deg2rad($p_lat - $avg_lat);
                    $dLon = deg2rad($p_lon - $avg_lon);
                    $a    = sin($dLat/2)*sin($dLat/2)
                          + cos(deg2rad($avg_lat)) * cos(deg2rad($p_lat)) * sin($dLon/2)*sin($dLon/2);
                    $player['km_from_centroid'] = round($R * 2 * atan2(sqrt($a), sqrt(1-$a)), 1);
                } else {
                    $player['km_from_centroid'] = null;
                }
            }
            unset($player);

            // re-rank by cosine similarity during exploitation
            $has_target = array_sum($target_vector) > 0;
            if (!$is_exploration && $has_target) {
                foreach ($candidates as &$player) {
                    $player_vec           = $this->buildPlayerVector($player);
                    $player['cosine_sim'] = $this->cosineSimilarity($target_vector, $player_vec);
                }
                unset($player);
                usort($candidates, fn($a, $b) => $b['cosine_sim'] <=> $a['cosine_sim']);
            }

            $players = array_slice($candidates, 0, 5);

            // logistic regression match probability using scout-specific weights
            foreach ($players as &$player) {
                $s_speed  = (float)($player['scaled_speed']  ?? 0.5);
                $s_height = (float)($player['scaled_height'] ?? 0.5);
                $s_age    = (float)($player['scaled_age']    ?? 0.5);

                $z = -3.0
                    + ($w_speed  * 3.0  * $s_speed)
                    + ($w_height * 1.5  * $s_height)
                    + ($w_age    * -1.5 * $s_age);

                $player['match_probability'] = round((1 / (1 + exp(-$z))) * 100);
            }
            unset($player);

            if (count($players) === 0) {
                $backup = $this->conn->query("SELECT * FROM PlayerProfiles ORDER BY RAND() LIMIT 5");
                while ($r = $backup->fetch_assoc()) {
                    $r['match_probability'] = 50;
                    $r['cosine_sim']        = 0;
                    $players[]              = $r;
                }
            }

            echo json_encode([
                "success" => true,
                "players" => $players,
                "algo_stats" => [
                    "mode"    => $is_exploration ? "exploration" : "exploitation",
                    "epsilon" => round($epsilon, 2),
                    "target_vector" => [
                        "age"    => $t_age,
                        "speed"  => $t_speed,
                        "height" => $t_height,
                        "weight" => $t_weight
                    ],
                    "scout_weights" => [
                        "speed"  => round($w_speed,  3),
                        "height" => round($w_height, 3),
                        "age"    => round($w_age,    3)
                    ],
                    "pos_weights" => [
                        "gk"   => round($w_pos_gk,   3),
                        "def"  => round($w_pos_def,  3),
                        "mid"  => round($w_pos_mid,  3),
                        "wing" => round($w_pos_wing, 3),
                        "str"  => round($w_pos_str,  3)
                    ],
                    "location" => [
                        "centroid_lat" => $avg_lat,
                        "centroid_lon" => $avg_lon
                    ]
                ]
            ]);
            exit;
        }

        if ($action === 'track_behavior') {
            $scout   = $data['scout_email'];
            $player  = $data['player_email'];
            $dwell   = (int)$data['dwell_time'];
            $clicked = isset($data['contact_clicked']) ? (int)$data['contact_clicked'] : 0;

            $r_i = ($dwell > 30) ? 0.2 : 0.05;
            if ($clicked) $r_i = min($r_i + 0.3, 0.5);
            $implicit_contribution = 0.2 * $r_i;

            $query = "INSERT INTO ScoutBehavior (scout_email, player_email, dwell_time, contact_clicked, combined_score)
                      VALUES (?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE
                      dwell_time      = dwell_time + VALUES(dwell_time),
                      contact_clicked = GREATEST(contact_clicked, VALUES(contact_clicked)),
                      combined_score  = LEAST(1.2, combined_score + VALUES(combined_score))";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ssiid", $scout, $player, $dwell, $clicked, $implicit_contribution);
            $stmt->execute();
            exit;
        }

        if ($action === 'rate_player') {
            $scout   = $data['scout_email'];
            $player  = $data['player_email'];
            $rating  = (int)$data['rating'];
            $r_e     = $rating / 100.0;
            $combined = min(1.2, (1.0 * $r_e) + 0.2);

            // save the rating and combined utility score
            $q_b = "INSERT INTO ScoutBehavior (scout_email, player_email, explicit_rating, combined_score)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    explicit_rating = VALUES(explicit_rating),
                    combined_score  = LEAST(1.2, VALUES(combined_score))";
            $stmt_b = $this->conn->prepare($q_b);
            $stmt_b->bind_param("ssid", $scout, $player, $rating, $combined);
            $stmt_b->execute();

            // auto-shortlist on full rating
            if ($rating === 100) {
                $q_s = "INSERT IGNORE INTO ScoutShortlist (scout_email, player_email, status) VALUES (?, ?, 'pending')";
                $stmt_s = $this->conn->prepare($q_s);
                $stmt_s->bind_param("ss", $scout, $player);
                $stmt_s->execute();
            }

            // increment interaction count for epsilon decay
            $q_p = "INSERT INTO ScoutPreferences
                        (scout_email, interaction_count,
                         weight_speed, weight_height, weight_age,
                         weight_pos_gk, weight_pos_def, weight_pos_mid, weight_pos_wing, weight_pos_str)
                    VALUES (?, 1, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0)
                    ON DUPLICATE KEY UPDATE interaction_count = interaction_count + 1";
            $stmt_p = $this->conn->prepare($q_p);
            $stmt_p->bind_param("s", $scout);
            $stmt_p->execute();

            // update feature weights via EWMA when the scout shows clear interest
            if ($rating >= 60) {
                $stmt_pp = $this->conn->prepare(
                    "SELECT scaled_speed, scaled_height, scaled_age,
                            pos_gk, pos_def, pos_mid, pos_wing, pos_str
                     FROM PlayerProfiles WHERE email = ?"
                );
                $stmt_pp->bind_param("s", $player);
                $stmt_pp->execute();
                $pp = $stmt_pp->get_result()->fetch_assoc();

                if ($pp) {
                    $alpha    = 0.08;
                    $rating_w = $rating / 100.0;
                    $decay    = 1.0 - $alpha;

                    $sig_speed  = 3.0 * (float)$pp['scaled_speed']  * $rating_w;
                    $sig_height = 3.0 * (float)$pp['scaled_height'] * $rating_w;
                    $sig_age    = 3.0 * (float)$pp['scaled_age']    * $rating_w;

                    $q_w = "INSERT INTO ScoutPreferences (scout_email, weight_speed, weight_height, weight_age, interaction_count)
                            VALUES (?, 1.0, 1.0, 1.0, 1)
                            ON DUPLICATE KEY UPDATE
                            weight_speed  = LEAST(3.0, GREATEST(0.2, COALESCE(weight_speed,  1.0) * ? + ? * ?)),
                            weight_height = LEAST(3.0, GREATEST(0.2, COALESCE(weight_height, 1.0) * ? + ? * ?)),
                            weight_age    = LEAST(3.0, GREATEST(0.2, COALESCE(weight_age,    1.0) * ? + ? * ?))";
                    $stmt_w = $this->conn->prepare($q_w);
                    $stmt_w->bind_param("sddddddddd",
                        $scout,
                        $decay, $alpha, $sig_speed,
                        $decay, $alpha, $sig_height,
                        $decay, $alpha, $sig_age
                    );
                    $stmt_w->execute();

                    $sig_gk   = 3.0 * (float)($pp['pos_gk']   ?? 0) * $rating_w;
                    $sig_def  = 3.0 * (float)($pp['pos_def']  ?? 0) * $rating_w;
                    $sig_mid  = 3.0 * (float)($pp['pos_mid']  ?? 0) * $rating_w;
                    $sig_wing = 3.0 * (float)($pp['pos_wing'] ?? 0) * $rating_w;
                    $sig_str  = 3.0 * (float)($pp['pos_str']  ?? 0) * $rating_w;

                    $q_pw = "INSERT INTO ScoutPreferences
                                 (scout_email, weight_pos_gk, weight_pos_def, weight_pos_mid, weight_pos_wing, weight_pos_str, interaction_count)
                             VALUES (?, 1.0, 1.0, 1.0, 1.0, 1.0, 0)
                             ON DUPLICATE KEY UPDATE
                             weight_pos_gk   = LEAST(3.0, GREATEST(0.2, COALESCE(weight_pos_gk,   1.0) * ? + ? * ?)),
                             weight_pos_def  = LEAST(3.0, GREATEST(0.2, COALESCE(weight_pos_def,  1.0) * ? + ? * ?)),
                             weight_pos_mid  = LEAST(3.0, GREATEST(0.2, COALESCE(weight_pos_mid,  1.0) * ? + ? * ?)),
                             weight_pos_wing = LEAST(3.0, GREATEST(0.2, COALESCE(weight_pos_wing, 1.0) * ? + ? * ?)),
                             weight_pos_str  = LEAST(3.0, GREATEST(0.2, COALESCE(weight_pos_str,  1.0) * ? + ? * ?))";
                    $stmt_pw = $this->conn->prepare($q_pw);
                    if ($stmt_pw) {
                        $stmt_pw->bind_param("sddddddddddddddd",
                            $scout,
                            $decay, $alpha, $sig_gk,
                            $decay, $alpha, $sig_def,
                            $decay, $alpha, $sig_mid,
                            $decay, $alpha, $sig_wing,
                            $decay, $alpha, $sig_str
                        );
                        $stmt_pw->execute();
                    }
                }
            }

            // read updated weights so the terminal panel can refresh
            $stmt_stats = $this->conn->prepare(
                "SELECT interaction_count, weight_speed, weight_height, weight_age,
                        weight_pos_gk, weight_pos_def, weight_pos_mid, weight_pos_wing, weight_pos_str
                 FROM ScoutPreferences WHERE scout_email = ?"
            );
            $stats = null;
            if ($stmt_stats) {
                $stmt_stats->bind_param("s", $scout);
                $stmt_stats->execute();
                $stats = $stmt_stats->get_result()->fetch_assoc();
            }
            $interactions_now = $stats ? (int)$stats['interaction_count'] : 0;
            $epsilon_now      = max(0.1, 1.0 - ($interactions_now * 0.05));
            $sw               = $stats ?: [];

            echo json_encode([
                "success"        => true,
                "is_shortlisted" => ($rating === 100),
                "algo_stats"     => [
                    "mode"    => $epsilon_now > 0.5 ? "exploration" : "exploitation",
                    "epsilon" => round($epsilon_now, 2),
                    "target_vector" => ["age" => 0.5, "speed" => 0.5, "height" => 0.5, "weight" => 0.5],
                    "scout_weights" => [
                        "speed"  => round((float)($sw['weight_speed']  ?? 1.0), 3),
                        "height" => round((float)($sw['weight_height'] ?? 1.0), 3),
                        "age"    => round((float)($sw['weight_age']    ?? 1.0), 3)
                    ],
                    "pos_weights" => [
                        "gk"   => round((float)($sw['weight_pos_gk']   ?? 1.0), 3),
                        "def"  => round((float)($sw['weight_pos_def']  ?? 1.0), 3),
                        "mid"  => round((float)($sw['weight_pos_mid']  ?? 1.0), 3),
                        "wing" => round((float)($sw['weight_pos_wing'] ?? 1.0), 3),
                        "str"  => round((float)($sw['weight_pos_str']  ?? 1.0), 3)
                    ]
                ]
            ]);
            exit;
        }

        if ($action === 'reset_algorithm') {
            $scout = $data['scout_email'] ?? '';
            if (!$scout) return ["success" => false, "message" => "Missing scout email"];

            $stmt_del = $this->conn->prepare("DELETE FROM ScoutBehavior WHERE scout_email = ?");
            $stmt_del->bind_param("s", $scout);
            $stmt_del->execute();

            $stmt_rst = $this->conn->prepare(
                "INSERT INTO ScoutPreferences
                     (scout_email, interaction_count,
                      weight_speed, weight_height, weight_age,
                      weight_pos_gk, weight_pos_def, weight_pos_mid, weight_pos_wing, weight_pos_str)
                 VALUES (?, 0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0)
                 ON DUPLICATE KEY UPDATE
                     interaction_count = 0,
                     weight_speed  = 1.0, weight_height = 1.0, weight_age    = 1.0,
                     weight_pos_gk = 1.0, weight_pos_def = 1.0, weight_pos_mid  = 1.0,
                     weight_pos_wing = 1.0, weight_pos_str = 1.0"
            );
            $stmt_rst->bind_param("s", $scout);
            return ["success" => $stmt_rst->execute()];
        }

        if ($action === 'toggle_shortlist') {
            $scout_email  = $data['scout_email']  ?? '';
            $player_email = $data['player_email'] ?? '';
            if (empty($scout_email) || empty($player_email)) {
                return ["success" => false, "message" => "Missing email data"];
            }
            $check = $this->conn->prepare(
                "SELECT id FROM ScoutShortlist WHERE scout_email = ? AND player_email = ?"
            );
            $check->bind_param("ss", $scout_email, $player_email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $stmt = $this->conn->prepare(
                    "DELETE FROM ScoutShortlist WHERE scout_email = ? AND player_email = ?"
                );
                $stmt->bind_param("ss", $scout_email, $player_email);
                return ["success" => $stmt->execute(), "status" => "removed"];
            } else {
                $stmt = $this->conn->prepare(
                    "INSERT INTO ScoutShortlist (scout_email, player_email, status) VALUES (?, ?, 'pending')"
                );
                $stmt->bind_param("ss", $scout_email, $player_email);
                return ["success" => $stmt->execute(), "status" => "added"];
            }
        }

        if ($action === 'get_shortlist') {
            $scoutEmail = $data['email'];
            $query = "SELECT p.*, s.status,
                      (SELECT COUNT(*) FROM ScoutMessages
                       WHERE sender_email = p.email AND receiver_email = ? AND is_read = 0) as unread_messages
                      FROM PlayerProfiles p
                      JOIN ScoutShortlist s ON p.email = s.player_email
                      WHERE s.scout_email = ?";
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                echo json_encode(["success" => false, "error" => $this->conn->error]);
                exit;
            }
            $stmt->bind_param("ss", $scoutEmail, $scoutEmail);
            $stmt->execute();
            $result  = $stmt->get_result();
            $players = [];
            while ($row = $result->fetch_assoc()) { $players[] = $row; }
            echo json_encode(["success" => true, "players" => $players]);
            exit;
        }

        if ($action === 'send_message') {
            $sender   = $data['sender_email'];
            $receiver = $data['receiver_email'];
            $text     = $data['message_text'];
            $stmt = $this->conn->prepare(
                "INSERT INTO ScoutMessages (sender_email, receiver_email, message_text) VALUES (?, ?, ?)"
            );
            $stmt->bind_param("sss", $sender, $receiver, $text);
            return ["success" => $stmt->execute()];
        }

        if ($action === 'get_chat_history') {
            $user1 = $data['user1'];
            $user2 = $data['user2'];
            $query = "SELECT * FROM ScoutMessages
                      WHERE (sender_email = ? AND receiver_email = ?)
                      OR    (sender_email = ? AND receiver_email = ?)
                      ORDER BY created_at ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ssss", $user1, $user2, $user2, $user1);
            $stmt->execute();
            $result   = $stmt->get_result();
            $messages = [];
            while ($row = $result->fetch_assoc()) { $messages[] = $row; }
            echo json_encode(["success" => true, "messages" => $messages]);
            exit;
        }

        if ($action === 'mark_as_read') {
            $me   = $data['my_email'];
            $them = $data['other_email'];
            $stmt = $this->conn->prepare(
                "UPDATE ScoutMessages SET is_read = 1 WHERE receiver_email = ? AND sender_email = ? AND is_read = 0"
            );
            $stmt->bind_param("ss", $me, $them);
            $stmt->execute();
            return ["success" => true];
        }

        if ($action === 'get_unread_count') {
            $email = $data['email'];
            $stmt  = $this->conn->prepare(
                "SELECT COUNT(*) as unread FROM ScoutMessages WHERE receiver_email = ? AND is_read = 0"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            return ["success" => true, "count" => (int)$result['unread']];
        }

        if ($action === 'get_player_matches') {
            $player_email = $data['email'];
            $query = "SELECT s.status, s.scout_email, sp.full_name, sp.club, sp.location,
                             sp.picture_url, sp.league, sp.phone_number,
                      (SELECT COUNT(*) FROM ScoutMessages
                       WHERE sender_email = sp.email AND receiver_email = ? AND is_read = 0) as unread_messages
                      FROM ScoutShortlist s
                      JOIN ScoutProfiles sp ON s.scout_email = sp.email
                      WHERE s.player_email = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ss", $player_email, $player_email);
            $stmt->execute();
            $result  = $stmt->get_result();
            $matches = [];
            while ($row = $result->fetch_assoc()) { $matches[] = $row; }
            echo json_encode(["success" => true, "matches" => $matches]);
            exit;
        }

        if ($action === 'update_match_status') {
            $player_email = $data['player_email'];
            $scout_email  = $data['scout_email'];
            $new_status   = $data['status'];
            $stmt = $this->conn->prepare(
                "UPDATE ScoutShortlist SET status = ? WHERE player_email = ? AND scout_email = ?"
            );
            $stmt->bind_param("sss", $new_status, $player_email, $scout_email);
            return ["success" => $stmt->execute(), "message" => "Status updated to " . $new_status];
        }

        if ($action === 'get_scout_profile') {
            $stmt = $this->conn->prepare("SELECT * FROM ScoutProfiles WHERE email = ?");
            $stmt->bind_param("s", $data['email']);
            $stmt->execute();
            $profile = $stmt->get_result()->fetch_assoc();
            return ["success" => true, "profile" => $profile ?: ["full_name" => ""]];
        }

        if ($action === 'update_scout_profile') {
            $email       = $data['email'];
            $picture_url = "";
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
                $dir      = $_SERVER['DOCUMENT_ROOT'] . '/Images/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fileName = 'scout_' . time() . '_' . basename($_FILES['profile_picture']['name']);
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dir . $fileName)) {
                    $picture_url = 'https://ao657.brighton.domains/Images/' . $fileName;
                }
            }
            $sql = "INSERT INTO ScoutProfiles (email, full_name, club, location, phone_number, league, picture_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    full_name=VALUES(full_name), club=VALUES(club), location=VALUES(location),
                    phone_number=VALUES(phone_number), league=VALUES(league),
                    picture_url=IF(VALUES(picture_url)='', picture_url, VALUES(picture_url))";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("sssssss",
                $email, $data['full_name'], $data['club'],
                $data['location'], $data['phone_number'], $data['league'], $picture_url
            );
            return ["success" => $stmt->execute(), "message" => "Scout Profile Updated", "url" => $picture_url];
        }

        if ($action === 'get_player_profile') {
            $stmt = $this->conn->prepare("SELECT * FROM PlayerProfiles WHERE email = ?");
            $stmt->bind_param("s", $data['email']);
            $stmt->execute();
            $profile = $stmt->get_result()->fetch_assoc();
            return ["success" => true, "profile" => $profile ?: ["full_name" => ""]];
        }

        if ($action === 'update_player_profile') {
            $email       = $data['email'];
            $picture_url = "";

            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
                $dir      = $_SERVER['DOCUMENT_ROOT'] . '/Images/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fileName = 'player_' . time() . '_' . basename($_FILES['profile_picture']['name']);
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dir . $fileName)) {
                    $picture_url = 'https://ao657.brighton.domains/Images/' . $fileName;
                }
            }

            // geocode the player's town using Nominatim
            $town = $data['nearest_town'];
            $lat  = 0; $lng = 0;
            $opts    = ['http' => ['header' => "User-Agent: ScoutApp/1.0\r\n", 'timeout' => 3]];
            $context = stream_context_create($opts);
            $geo_url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($town) . "&limit=1";
            $geo_data = @file_get_contents($geo_url, false, $context);
            if ($geo_data) {
                $geo_res = json_decode($geo_data, true);
                if (!empty($geo_res)) {
                    $lat  = $geo_res[0]['lat'];
                    $lng  = $geo_res[0]['lon'];
                    $town = explode(',', $geo_res[0]['display_name'])[0];
                }
            }

            // min-max scale each physical attribute to [0, 1] using real-world football bounds
            $s_age    = min(max(((float)$data['age']       - 15.5) / (43.0  - 15.5), 0.0), 1.0);
            $s_height = min(max(((float)$data['height']    - 160)  / (206.0 - 160.0), 0.0), 1.0);
            $s_weight = min(max(((float)$data['weight']    - 55)   / (110.0 - 55.0),  0.0), 1.0);
            $s_speed  = min(max(((float)$data['top_speed'] - 20)   / (45.0  - 20.0),  0.0), 1.0);
            $games    = (int)$data['games_played'];
            $gpg      = ($games > 0) ? ((float)$data['goals'] / $games) : 0.0;
            $s_gpg    = min($gpg / 2.0, 1.0);

            // one-hot encode primary and secondary positions
            $p_pos  = (int)$data['preferred_position'];
            $p_gk   = ($p_pos === 1) ? 1 : 0; $p_def  = ($p_pos === 2) ? 1 : 0;
            $p_mid  = ($p_pos === 3) ? 1 : 0; $p_wing = ($p_pos === 4) ? 1 : 0; $p_str = ($p_pos === 5) ? 1 : 0;

            $s_pos  = (int)$data['secondary_positions'];
            $s_gk   = ($s_pos === 1) ? 1 : 0; $s_def  = ($s_pos === 2) ? 1 : 0;
            $s_mid  = ($s_pos === 3) ? 1 : 0; $s_wing = ($s_pos === 4) ? 1 : 0; $s_str = ($s_pos === 5) ? 1 : 0;

            $sql = "INSERT INTO PlayerProfiles (
                        email, full_name, phone_number, languages, nearest_town, previous_clubs, relocate,
                        age, height, weight, games_played, goals, assists, top_speed, profile_type, preferred_foot,
                        latitude, longitude,
                        scaled_age, scaled_height, scaled_weight, scaled_speed, scaled_goals_per_game,
                        pos_gk, pos_def, pos_mid, pos_wing, pos_str,
                        s_pos_gk, s_pos_def, s_pos_mid, s_pos_wing, s_pos_str,
                        picture_url
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?
                    )
                    ON DUPLICATE KEY UPDATE
                        full_name=VALUES(full_name), phone_number=VALUES(phone_number),
                        languages=VALUES(languages), nearest_town=VALUES(nearest_town),
                        previous_clubs=VALUES(previous_clubs), relocate=VALUES(relocate),
                        age=VALUES(age), height=VALUES(height), weight=VALUES(weight),
                        games_played=VALUES(games_played), goals=VALUES(goals), assists=VALUES(assists),
                        top_speed=VALUES(top_speed), profile_type=VALUES(profile_type),
                        preferred_foot=VALUES(preferred_foot), latitude=VALUES(latitude), longitude=VALUES(longitude),
                        scaled_age=VALUES(scaled_age), scaled_height=VALUES(scaled_height),
                        scaled_weight=VALUES(scaled_weight), scaled_speed=VALUES(scaled_speed),
                        scaled_goals_per_game=VALUES(scaled_goals_per_game),
                        pos_gk=VALUES(pos_gk), pos_def=VALUES(pos_def), pos_mid=VALUES(pos_mid),
                        pos_wing=VALUES(pos_wing), pos_str=VALUES(pos_str),
                        s_pos_gk=VALUES(s_pos_gk), s_pos_def=VALUES(s_pos_def),
                        s_pos_mid=VALUES(s_pos_mid), s_pos_wing=VALUES(s_pos_wing), s_pos_str=VALUES(s_pos_str),
                        picture_url=IF(VALUES(picture_url)='', picture_url, VALUES(picture_url))";

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(["success" => false, "message" => "Prepare failed: " . $this->conn->error]);
                return;
            }
            $stmt->bind_param(
                "sssssssiiiiiidiidddddddiiiiiiiiiis",
                $email, $data['full_name'], $data['phone_number'], $data['languages'],
                $town,  $data['previous_clubs'], $data['relocate'],
                $data['age'], $data['height'], $data['weight'],
                $data['games_played'], $data['goals'], $data['assists'],
                $data['top_speed'], $data['profile_type'], $data['preferred_foot'],
                $lat, $lng,
                $s_age, $s_height, $s_weight, $s_speed, $s_gpg,
                $p_gk, $p_def, $p_mid, $p_wing, $p_str,
                $s_gk, $s_def, $s_mid, $s_wing, $s_str,
                $picture_url
            );

            $success = $stmt->execute();
            echo json_encode([
                "success"           => $success,
                "message"           => $success ? "Profile Vectorized" : $stmt->error,
                "standardized_town" => $town,
                "scaled_values"     => [
                    "age"    => round($s_age,    3),
                    "height" => round($s_height, 3),
                    "weight" => round($s_weight, 3),
                    "speed"  => round($s_speed,  3),
                    "gpg"    => round($s_gpg,    3)
                ]
            ]);
            exit;
        }
    }
}

$handler = new LoginHandler();
echo json_encode($handler->handleRequest());
?>
