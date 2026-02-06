"""
Helpers pour la connexion MariaDB et le chargement des messages.
"""

import os
import mysql.connector
from mysql.connector import Error

def _get_db_config():
    return {
        "host": os.getenv("DB_HOST", "localhost"),
        "port": os.getenv("DB_PORT", "3306"),
        "database": os.getenv("DB_NAME"),
        "user": os.getenv("DB_USER"),
        "password": os.getenv("DB_PASSWORD"),
    }


def get_db_connection(db_config=None):
    """Établit la connexion à MariaDB."""
    return mysql.connector.connect(**(db_config or _get_db_config()))


def get_all_user_ids(cutoff_date, db_config=None):
    """
    Récupère tous les m_uid distincts ayant des messages avant la date limite.

    Args:
        cutoff_date: Date limite (YYYY-MM-DD)
        db_config: configuration MariaDB optionnelle

    Returns:
        Liste de m_uid (strings)
    """
    conn = None
    user_ids = []

    try:
        conn = get_db_connection(db_config)
        cur = conn.cursor()

        query = """
        SELECT DISTINCT m_uid
        FROM messages
        WHERE m_date < %s AND m_uid IS NOT NULL
        ORDER BY m_uid ASC
        """
        cur.execute(query, (cutoff_date,))

        user_ids = [str(row[0]) for row in cur.fetchall()]
        print(f"✅ {len(user_ids)} utilisateur(s) trouvé(s) avec des messages avant {cutoff_date}.")
    except Error as e:
        print(f"❌ Erreur MariaDB : {e}")
    except Exception as e:
        print(f"❌ Erreur : {e}")
    finally:
        if conn and conn.is_connected():
            cur.close()
            conn.close()

    return user_ids


def load_messages_from_db(external_id, cutoff_date, db_config=None):
    """
    Récupère les messages MariaDB avant une date donnée.
    
    Args:
        external_id: ID externe de l'utilisateur
        cutoff_date: Date limite (YYYY-MM-DD)
        db_config: configuration MariaDB optionnelle
    
    Returns:
        Liste de messages au format {author_type, text}
    """
    conn = None
    messages = []
    
    try:
        conn = get_db_connection(db_config)
        cur = conn.cursor()
        
        query = """
        SELECT m_content, m_reply_uid, m_date, m_done
        FROM messages
        WHERE m_uid = %s AND m_date < %s
        ORDER BY m_date ASC
        """
        cur.execute(query, (external_id, cutoff_date))

        rows = cur.fetchall()
        for m_content, m_reply_uid, _, m_done in rows:
            author_type = "user" if (m_reply_uid is None or int(m_reply_uid) == 0) else "business"
            messages.append({"author_type": author_type, "text": m_content, "done": int(m_done) if m_done is not None else 0})
        
        print(f"✅ {len(messages)} message(s) récupéré(s) depuis MariaDB (avant {cutoff_date}).")
    except Error as e:
        print(f"❌ Erreur MariaDB : {e}")
    except Exception as e:
        print(f"❌ Erreur : {e}")
    finally:
        if conn and conn.is_connected():
            cur.close()
            conn.close()
    
    return messages
