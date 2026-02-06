# Utils package - API SunCo et Zendesk
from .sunco import (
    get_sunco_auth,
    get_sunco_base_url,
    delete_sunco_user,
    ensure_sunco_user,
    create_conversation,
    post_historical_message,
    migrate_conversation,
    create_sunco_conversation,
    get_user_conversations,
    delete_conversation,
)
from .zendesk import (
    get_zendesk_auth,
    get_zendesk_base_url,
    find_latest_zendesk_ticket_for_user,
    update_zendesk_ticket,
)
from .sync import sync_to_zendesk

__all__ = [
    # SunCo
    "get_sunco_auth",
    "get_sunco_base_url",
    "delete_sunco_user",
    "ensure_sunco_user",
    "create_conversation",
    "post_historical_message",
    "migrate_conversation",
    "create_sunco_conversation",
    "get_user_conversations",
    "delete_conversation",
    # Zendesk
    "get_zendesk_auth",
    "get_zendesk_base_url",
    "find_latest_zendesk_ticket_for_user",
    "update_zendesk_ticket",
    # Sync
    "sync_to_zendesk",
]
