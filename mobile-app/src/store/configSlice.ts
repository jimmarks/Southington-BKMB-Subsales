import { createSlice, PayloadAction } from '@reduxjs/toolkit';

export interface AppConfig {
  google_maps_api_key: string;
  app_version: string;
}

interface ConfigState {
  config: AppConfig | null;
  isLoading: boolean;
  error: string | null;
  lastUpdated: number | null;
}

const initialState: ConfigState = {
  config: null,
  isLoading: false,
  error: null,
  lastUpdated: null,
};

const configSlice = createSlice({
  name: 'config',
  initialState,
  reducers: {
    setConfigLoading(state, action: PayloadAction<boolean>) {
      state.isLoading = action.payload;
      if (action.payload) {
        state.error = null;
      }
    },
    setConfigSuccess(state, action: PayloadAction<AppConfig>) {
      state.config = action.payload;
      state.isLoading = false;
      state.error = null;
      state.lastUpdated = Date.now();
    },
    setConfigError(state, action: PayloadAction<string>) {
      state.error = action.payload;
      state.isLoading = false;
    },
    clearConfig(state) {
      state.config = null;
      state.error = null;
      state.lastUpdated = null;
    },
  },
});

export const { 
  setConfigLoading, 
  setConfigSuccess, 
  setConfigError, 
  clearConfig 
} = configSlice.actions;

export default configSlice.reducer;