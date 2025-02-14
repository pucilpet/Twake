import Repository from "../../../../../../core/platform/services/database/services/orm/repository/repository";
import { MessageUserMarkedRef } from "../../../../entities/message-user-marked_refs";
import gr from "../../../../../global-resolver";

export class UserMarkedViewProcessor {
  repository: Repository<MessageUserMarkedRef>;

  async init(): Promise<void> {
    this.repository = await gr.database.getRepository<MessageUserMarkedRef>(
      "message_user_marked_refs",
      MessageUserMarkedRef,
    );
  }

  async process(): Promise<void> {
    //
  }
}
