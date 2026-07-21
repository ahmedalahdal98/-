"""محادثة تيليجرام مع مستخدم محدد في private_config.py."""

from __future__ import annotations

import argparse
import asyncio
from pathlib import Path

from telethon import TelegramClient, events, types
from telethon.errors import ChatAdminRequiredError, ChannelPrivateError, RPCError

try:
    from private_config import API_HASH, API_ID, SESSION_NAME, TARGET_USER_ID
except ImportError as exc:
    raise SystemExit("ملف private_config.py غير موجود بجانب البرنامج.") from exc


APP_DIR = Path(__file__).resolve().parent


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="مراسلة المستخدم المحدد داخل private_config.py",
    )
    parser.add_argument(
        "--message",
        help="إرسال رسالة واحدة والخروج بدل فتح المحادثة التفاعلية",
    )
    parser.add_argument(
        "--yes",
        action="store_true",
        help="فتح المحادثة مباشرة دون انتظار تأكيد المستلم",
    )
    parser.add_argument(
        "--list-users",
        action="store_true",
        help="عرض آيديات المحادثات الخاصة ثم الخروج",
    )
    return parser.parse_args()


def read_config(require_target: bool = True) -> tuple[int, str, int, Path]:
    try:
        api_id = int(API_ID)
        target_user_id = int(TARGET_USER_ID)
    except (TypeError, ValueError) as exc:
        raise SystemExit("API_ID و TARGET_USER_ID يجب أن يكونا رقمين صحيحين.") from exc

    api_hash = str(API_HASH).strip()
    session_name = str(SESSION_NAME).strip()

    if api_id <= 0:
        raise SystemExit("ضع API_ID الصحيح داخل private_config.py.")
    if not api_hash or api_hash == "ضع_API_HASH_هنا":
        raise SystemExit("ضع API_HASH الصحيح داخل private_config.py.")
    if require_target and target_user_id <= 0:
        raise SystemExit("ضع آيدي الشخص الصحيح في TARGET_USER_ID داخل private_config.py.")
    if not session_name or Path(session_name).name != session_name:
        raise SystemExit("SESSION_NAME يجب أن يكون اسمًا بسيطًا مثل telegram_user_correct.")

    return api_id, api_hash, target_user_id, APP_DIR / session_name


def display_name(user: types.User) -> str:
    name = " ".join(part for part in (user.first_name, user.last_name) if part).strip()
    if user.username:
        return f"{name or 'بدون اسم'} (@{user.username})"
    return name or "بدون اسم مستخدم"


async def show_logged_in_account(client: TelegramClient) -> None:
    me = await client.get_me()
    if isinstance(me, types.User):
        print(f"الحساب المتصل: {display_name(me)} (ID: {me.id})")


async def list_private_users(client: TelegramClient) -> None:
    print("\nالمحادثات الخاصة التي يستطيع البرنامج رؤيتها:\n")
    count = 0
    async for dialog in client.iter_dialogs():
        if not isinstance(dialog.entity, types.User):
            continue
        count += 1
        print(f"{dialog.entity.id}  |  {display_name(dialog.entity)}")

    print(f"\nالمجموع: {count}" if count else "لا توجد محادثات خاصة ظاهرة.")


async def find_user_in_groups(
    client: TelegramClient,
    dialogs: list,
    user_id: int,
) -> tuple[types.TypeInputPeer, types.User, str] | None:
    """Search accessible group member lists for one exact user ID."""
    for dialog in dialogs:
        if not dialog.is_group:
            continue

        try:
            async for participant in client.iter_participants(dialog.input_entity):
                if isinstance(participant, types.User) and participant.id == user_id:
                    input_peer = await client.get_input_entity(participant)
                    return input_peer, participant, dialog.name or "بدون اسم"
        except (ChatAdminRequiredError, ChannelPrivateError):
            # Telegram does not expose every group's member list to every account.
            continue

    return None


async def resolve_target(
    client: TelegramClient,
    user_id: int,
) -> tuple[types.TypeInputPeer, types.User]:
    if user_id <= 0:
        raise ValueError("آيدي الشخص يجب أن يكون رقمًا موجبًا.")

    dialogs = await client.get_dialogs(limit=None)
    try:
        input_peer = await client.get_input_entity(types.PeerUser(user_id))
        entity = await client.get_entity(input_peer)
    except (ValueError, TypeError):
        print("لم أجده في المحادثات الخاصة؛ جاري البحث بين أعضاء القروبات...")
        result = await find_user_in_groups(client, dialogs, user_id)
        if result is None:
            raise LookupError(
                "لم أجد هذا الآيدي في الخاص أو أعضاء القروبات المتاحة. "
                "تأكد من الآيدي ومن أن الشخص موجود معك في قروب ظاهر للحساب."
            )

        input_peer, entity, group_title = result
        print(f"تم العثور عليه في القروب: {group_title}")

    if not isinstance(entity, types.User):
        raise LookupError("الآيدي المحدد لا يعود إلى مستخدم تيليجرام.")

    return input_peer, entity


async def confirm_recipient(user: types.User, skip_confirmation: bool) -> None:
    print(f"\nالمستلم: {display_name(user)}")
    print(f"ID: {user.id}")
    if skip_confirmation:
        return

    answer = await asyncio.to_thread(
        input,
        "اضغط Enter للمتابعة، أو اكتب لا للإلغاء: ",
    )
    if answer.strip().casefold() in {"لا", "no", "n"}:
        raise SystemExit("تم الإلغاء دون إرسال أي رسالة.")


async def interactive_chat(client: TelegramClient, input_peer: types.TypeInputPeer) -> None:
    print("\nبدأت المحادثة. اكتب /خروج أو /exit للإنهاء.\n")

    @client.on(events.NewMessage(incoming=True, from_users=input_peer))
    async def show_incoming(event: events.NewMessage.Event) -> None:
        text = event.raw_text or "[رسالة غير نصية]"
        print(f"\nهو/هي: {text}\nأنت: ", end="", flush=True)

    while True:
        message = (await asyncio.to_thread(input, "أنت: ")).strip()
        if message.casefold() in {"/خروج", "/exit", "/quit"}:
            break
        if not message:
            continue

        await client.send_message(input_peer, message)
        print("تم الإرسال.")


async def main() -> None:
    args = parse_args()
    api_id, api_hash, target_user_id, session_path = read_config(
        require_target=not args.list_users
    )

    client = TelegramClient(str(session_path), api_id, api_hash)
    try:
        await client.start()
        await show_logged_in_account(client)

        if args.list_users:
            await list_private_users(client)
            return

        input_peer, user = await resolve_target(client, target_user_id)
        await confirm_recipient(user, args.yes)

        if args.message is not None:
            message = args.message.strip()
            if not message:
                raise SystemExit("الرسالة لا يمكن أن تكون فارغة.")
            await client.send_message(input_peer, message)
            print("تم إرسال الرسالة بنجاح.")
            return

        await interactive_chat(client, input_peer)
    except (LookupError, ValueError) as exc:
        raise SystemExit(str(exc)) from exc
    except RPCError as exc:
        raise SystemExit(f"رفض تيليجرام الطلب: {type(exc).__name__}: {exc}") from exc
    finally:
        await client.disconnect()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\nتم إيقاف البرنامج.")
